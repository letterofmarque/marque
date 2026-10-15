<?php

declare(strict_types=1);

// Job #112 CP3 (Spec #121): bloodhound:rebuild-totals recomputes seedtime and
// leechtime from the ledger.
//
// This is what makes seedtime a projection rather than an accumulator: a
// wrong number can be thrown away and derived again. Asserted by zeroing and
// comparing against what the aggregator produced, not by checking for
// non-zero, which a rebuild that was consistently wrong would also pass.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'p']);
    $this->other = TestUser::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'p']);

    $this->torrent = Torrent::create([
        'name' => 'One', 'info_hash' => str_repeat('a', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);
    $this->second = Torrent::create([
        'name' => 'Two', 'info_hash' => str_repeat('b', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);

    $this->t = Carbon::parse('2026-10-01 10:00:00');
});

function rebuildRow($test, TestUser $user, Torrent $torrent, string $event, int $left, int $minutes, int $up = 0, string $peer = '-qB4210-aaaaaaaaaaaa'): void
{
    $row = AnnounceLog::create([
        'user_id' => $user->id,
        'torrent_id' => $torrent->id,
        'peer_id' => $peer,
        'event' => $event,
        'ip' => '10.0.0.1',
        'port' => 51413,
        'uploaded' => $up,
        'downloaded' => 0,
        'left' => $left,
        'upload_delta' => $up,
        'download_delta' => 0,
    ]);

    $row->forceFill(['created_at' => $test->t->copy()->addMinutes($minutes)])->save();
}

/**
 * Two users, two torrents, two peers; a completion, a cap, a stop and a
 * return. Enough for a wrong pairing or a dropped row to change a total.
 */
function seedHistory($test): void
{
    rebuildRow($test, $test->user, $test->torrent, 'started', 1_000, 0);
    rebuildRow($test, $test->other, $test->torrent, 'started', 0, 2, peer: '-TR3000-bbbbbbbbbbbb');
    rebuildRow($test, $test->user, $test->torrent, 'regular', 600, 30, up: 100);
    rebuildRow($test, $test->user, $test->second, 'started', 0, 31, peer: '-qB4210-cccccccccccc');
    rebuildRow($test, $test->other, $test->torrent, 'regular', 0, 32, up: 4_000, peer: '-TR3000-bbbbbbbbbbbb');
    rebuildRow($test, $test->user, $test->torrent, 'completed', 0, 60);
    rebuildRow($test, $test->user, $test->second, 'stopped', 0, 61, peer: '-qB4210-cccccccccccc');
    rebuildRow($test, $test->user, $test->torrent, 'regular', 0, 400);
    rebuildRow($test, $test->user, $test->second, 'started', 0, 500, peer: '-qB4210-cccccccccccc');
    rebuildRow($test, $test->user, $test->second, 'regular', 0, 530, peer: '-qB4210-cccccccccccc');
}

/**
 * Every time figure the projections hold, keyed so two snapshots compare.
 *
 * @return array<string, array{int, int}>
 */
function timeSnapshot($test): array
{
    $snapshot = [];

    foreach ([$test->user, $test->other] as $user) {
        $row = DB::table($user->getTable())->where('id', $user->id)->first();
        $snapshot["user:{$user->id}"] = [(int) $row->seedtime, (int) $row->leechtime];
    }

    foreach (TorrentUser::query()->orderBy('id')->get() as $row) {
        $snapshot["torrent_user:{$row->user_id}:{$row->torrent_id}"] = [$row->seedtime, $row->leechtime];
    }

    return $snapshot;
}

function zeroTime($test): void
{
    DB::table($test->user->getTable())->update(['seedtime' => 0, 'leechtime' => 0]);
    DB::table('torrent_user')->update(['seedtime' => 0, 'leechtime' => 0]);
}

test('a rebuild from zero reaches exactly what the aggregator produced', function () {
    seedHistory($this);
    app(LedgerAggregator::class)->run(batchSize: 3);
    $aggregated = timeSnapshot($this);

    zeroTime($this);
    app(LedgerRebuilder::class)->rebuild();

    // The aggregator's figures, checked by hand so a rebuild and an
    // aggregator that agreed on a wrong number would still fail.
    //   user on One: leech 1800 + 1800, then 340 min capped -> seed 3600
    //   user on Two: seed 1800 to the stop, nothing across it, seed 1800 after
    //   other on One: seed 1800
    expect($aggregated)->toBe([
        "user:{$this->user->id}" => [7200, 3600],
        "user:{$this->other->id}" => [1800, 0],
        "torrent_user:{$this->user->id}:{$this->torrent->id}" => [3600, 3600],
        "torrent_user:{$this->other->id}:{$this->torrent->id}" => [1800, 0],
        "torrent_user:{$this->user->id}:{$this->second->id}" => [3600, 0],
    ])->and(timeSnapshot($this))->toBe($aggregated);
});

test('stale time with no ledger behind it is cleared, not left standing', function () {
    seedHistory($this);
    app(LedgerAggregator::class)->run();

    // Time on a user, and on a torrent_user row, that no surviving ledger row
    // supports — what is left after pruning, or after a bad write.
    $third = TestUser::create(['name' => 'C', 'email' => 'c@example.com', 'password' => 'p']);
    DB::table($third->getTable())->where('id', $third->id)->update(['seedtime' => 999, 'leechtime' => 999]);
    DB::table('torrent_user')->insert([
        'user_id' => $third->id, 'torrent_id' => $this->second->id,
        'seedtime' => 999, 'leechtime' => 999, 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(LedgerRebuilder::class)->rebuild();

    $row = DB::table($third->getTable())->where('id', $third->id)->first();
    $torrentUser = TorrentUser::query()->where('user_id', $third->id)->first();

    expect([(int) $row->seedtime, (int) $row->leechtime])->toBe([0, 0])
        ->and([$torrentUser->seedtime, $torrentUser->leechtime])->toBe([0, 0]);
});

test('a scoped rebuild recomputes one user and leaves the rest alone', function () {
    seedHistory($this);
    app(LedgerAggregator::class)->run();

    DB::table($this->user->getTable())->update(['seedtime' => 5, 'leechtime' => 5]);

    app(LedgerRebuilder::class)->rebuild($this->user->id);

    $mine = DB::table($this->user->getTable())->where('id', $this->user->id)->first();
    $theirs = DB::table($this->other->getTable())->where('id', $this->other->id)->first();

    expect([(int) $mine->seedtime, (int) $mine->leechtime])->toBe([7200, 3600])
        ->and([(int) $theirs->seedtime, (int) $theirs->leechtime])->toBe([5, 5]);
});

// The upgrade path. Before Spec #121 the aggregator folded these rows and
// wrote seedtime 0; the cursor is past them, so the aggregator will never
// look at them again. A rebuild derives their time from the rows themselves.
test('rebuild backfills time for rows the old aggregator already folded', function () {
    seedHistory($this);
    LedgerCursor::create([
        'stream' => LedgerAggregator::STREAM,
        'position' => (int) AnnounceLog::query()->max('id'),
    ]);

    app(LedgerRebuilder::class)->rebuild();

    $mine = DB::table($this->user->getTable())->where('id', $this->user->id)->first();

    expect([(int) $mine->seedtime, (int) $mine->leechtime])->toBe([7200, 3600]);
});

// Rows above the watermark are a backlog the next aggregation run will fold.
// A rebuild that counted them would credit them twice.
test('rebuild counts time only up to the watermark', function () {
    rebuildRow($this, $this->user, $this->torrent, 'started', 0, 0);
    rebuildRow($this, $this->user, $this->torrent, 'regular', 0, 30);
    app(LedgerAggregator::class)->run();
    rebuildRow($this, $this->user, $this->torrent, 'regular', 0, 60);

    app(LedgerRebuilder::class)->rebuild();
    $afterRebuild = (int) DB::table($this->user->getTable())->where('id', $this->user->id)->value('seedtime');

    app(LedgerAggregator::class)->run();
    $afterCatchUp = (int) DB::table($this->user->getTable())->where('id', $this->user->id)->value('seedtime');

    expect($afterRebuild)->toBe(1800)->and($afterCatchUp)->toBe(3600);
});

// Criterion 8: set-based. A rebuild that walked rows in PHP would issue more
// queries as the ledger grew; this one's count depends on the number of
// (user, torrent) totals it writes, never on the number of announces.
test('the rebuild issues the same queries however long the ledger is', function () {
    $countQueries = function (int $announces): int {
        DB::table('announce_log')->delete();
        DB::table('ledger_cursors')->delete();

        for ($i = 0; $i < $announces; $i++) {
            rebuildRow($this, $this->user, $this->torrent, 'regular', 0, $i * 30);
        }

        app(LedgerAggregator::class)->run();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(LedgerRebuilder::class)->rebuild();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($countQueries(5))->toBe($countQueries(60));
});

test('the command rebuilds time too', function () {
    seedHistory($this);
    app(LedgerAggregator::class)->run();
    zeroTime($this);

    $this->artisan('bloodhound:rebuild-totals')->assertSuccessful();

    $mine = DB::table($this->user->getTable())->where('id', $this->user->id)->first();

    expect([(int) $mine->seedtime, (int) $mine->leechtime])->toBe([7200, 3600]);
});
