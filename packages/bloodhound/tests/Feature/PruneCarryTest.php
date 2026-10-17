<?php

declare(strict_types=1);

// Job #187 CP2 (#11021): pruning carries forward what it deletes.
//
// Before this, a prune deleted aggregated rows and kept nothing. Reconcile
// read every pruned user as drift, said "run bloodhound:rebuild-totals", and
// the rebuild erased the pruned credit: real users' ratios went down. Now each
// row carries its own credit (Job #193), and the prune carries exactly what
// the rows it deletes credited, in the same transaction as the delete.
//
// Each test is built so the old prune fails it.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerAuditor;
use Marque\Bloodhound\Services\LedgerPruner;
use Marque\Bloodhound\Services\LedgerRebuilder;
use Marque\Bloodhound\Services\OpeningBalance;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
    config(['bloodhound.announce_log.retention_days' => 30]);

    $this->user = TestUser::create(['name' => 'A', 'email' => 'prune-a@example.com', 'password' => 'p']);
    $this->other = TestUser::create(['name' => 'B', 'email' => 'prune-b@example.com', 'password' => 'p']);
    $this->torrent = Torrent::create([
        'name' => 'One', 'info_hash' => str_repeat('a', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);
    $this->second = Torrent::create([
        'name' => 'Two', 'info_hash' => str_repeat('b', 40), 'size' => 1_000, 'user_id' => $this->user->id,
    ]);

    // The retention line: rows created before this are pruned.
    $this->cutoff = Carbon::now()->subDays(30);
});

/**
 * One announce, at a time relative to the retention cutoff.
 */
function pruneRow($test, TestUser $user, Torrent|int $torrent, string $event, int $left, int $minutesFromCutoff, int $up = 0, int $down = 0, string $peer = '-qB4210-aaaaaaaaaaaa', int $seed = 0, int $leech = 0): AnnounceLog
{
    $row = AnnounceLog::create([
        'user_id' => $user->id,
        'torrent_id' => $torrent instanceof Torrent ? $torrent->id : $torrent,
        'peer_id' => $peer,
        'event' => $event,
        'ip' => '10.0.0.1',
        'port' => 51413,
        'uploaded' => $up,
        'downloaded' => $down,
        'left' => $left,
        'upload_delta' => $up,
        'download_delta' => $down,
        'seed_seconds' => $seed,
        'leech_seconds' => $leech,
    ]);

    $row->forceFill(['created_at' => $test->cutoff->copy()->addMinutes($minutesFromCutoff)])->save();

    return $row;
}

/**
 * A history straddling the cutoff: two users, two torrents, two peers,
 * a completion, a stop, and intervals that cross the line, each row with the
 * seconds the announce path credits it.
 */
function straddlingHistory($test): void
{
    pruneRow($test, $test->user, $test->torrent, 'started', 1_000, -300);
    pruneRow($test, $test->user, $test->torrent, 'regular', 400, -270, down: 600, leech: 1_800);
    pruneRow($test, $test->other, $test->torrent, 'started', 0, -260, peer: '-TR3000-bbbbbbbbbbbb');
    pruneRow($test, $test->user, $test->torrent, 'completed', 0, -240, up: 50, down: 400, leech: 1_800);
    pruneRow($test, $test->other, $test->torrent, 'regular', 0, -230, up: 7_000, peer: '-TR3000-bbbbbbbbbbbb', seed: 1_800);
    pruneRow($test, $test->user, $test->second, 'started', 0, -40, peer: '-qB4210-cccccccccccc');
    pruneRow($test, $test->user, $test->torrent, 'regular', 0, -10, up: 900, seed: 3_600);
    pruneRow($test, $test->user, $test->second, 'regular', 0, -10, up: 30, peer: '-qB4210-cccccccccccc', seed: 1_800);
    pruneRow($test, $test->user, $test->torrent, 'regular', 0, 20, up: 300, seed: 1_800);
    pruneRow($test, $test->other, $test->torrent, 'regular', 0, 25, up: 100, peer: '-TR3000-bbbbbbbbbbbb', seed: 3_600);
    pruneRow($test, $test->user, $test->second, 'stopped', 0, 20, peer: '-qB4210-cccccccccccc', seed: 1_800);
}

/**
 * Every figure both projections hold, for every user.
 *
 * @return array<string, array<int, int>>
 */
function pruneSnapshot($test): array
{
    $snapshot = [];

    foreach ([$test->user, $test->other] as $user) {
        $row = DB::table($user->getTable())->where('id', $user->id)->first();
        $snapshot["user:{$user->id}"] = [(int) $row->uploaded, (int) $row->downloaded, (int) $row->seedtime, (int) $row->leechtime];
    }

    foreach (TorrentUser::query()->orderBy('user_id')->orderBy('torrent_id')->get() as $row) {
        $snapshot["torrent_user:{$row->user_id}:{$row->torrent_id}"] = [$row->uploaded, $row->downloaded, $row->seedtime, $row->leechtime];
    }

    return $snapshot;
}

function prune($test): void
{
    $test->artisan('bloodhound:prune-announce-log')->assertSuccessful();
}

test('the prune deletes the aged rows it always did', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();

    prune($this);

    expect(AnnounceLog::query()->where('created_at', '<', $this->cutoff)->count())->toBe(0)
        ->and(AnnounceLog::query()->count())->toBe(3);
});

// Criterion 1.
test('after a prune, reconcile reports no drift', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();
    expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    prune($this);

    $report = app(LedgerAuditor::class)->reconcile();

    expect($report->userDrift)->toBe([])
        ->and($report->torrentUserDrift)->toBe([]);
});

// Criterion 2. The one #11021 is about: the rebuild reconcile recommends must
// give back what people had, not what survived the prune.
test('after a prune, a full rebuild reproduces the pre-prune totals', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    prune($this);
    app(LedgerRebuilder::class)->rebuild();

    expect(pruneSnapshot($this))->toBe($before);
});

test('after a prune, a scoped rebuild reproduces that user\'s totals', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    prune($this);
    app(LedgerRebuilder::class)->rebuild($this->user->id);
    app(LedgerRebuilder::class)->rebuild($this->other->id);

    expect(pruneSnapshot($this))->toBe($before);
});

// Criterion 3. A row's credit is its own: the prune carries exactly what the
// rows it deletes credited, and a surviving row keeps the interval it closes.
test('a prune carries the deleted rows\' own credit, and survivors keep theirs', function () {
    pruneRow($this, $this->user, $this->torrent, 'started', 0, -40);
    pruneRow($this, $this->user, $this->torrent, 'regular', 0, -10, up: 70, seed: 1_800);
    pruneRow($this, $this->user, $this->torrent, 'regular', 0, 10, up: 30, seed: 1_200);
    app(LedgerAggregator::class)->run();

    prune($this);

    $carry = LedgerCarry::query()->firstOrFail();

    expect([$carry->uploaded, $carry->seedtime])->toBe([70, 1_800])
        ->and(AnnounceLog::query()->pluck('seed_seconds')->all())->toBe([1_200])
        ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();
});

// Criterion 4. Delete and carry are one fact; neither may land alone.
test('a failure while carrying leaves the rows undeleted and nothing carried', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();
    $rows = AnnounceLog::query()->count();

    LedgerCarry::saving(fn () => throw new RuntimeException('carry write failed'));

    expect(fn () => $this->artisan('bloodhound:prune-announce-log'))->toThrow(RuntimeException::class);

    expect(AnnounceLog::query()->count())->toBe($rows)
        ->and(LedgerCarry::query()->count())->toBe(0);
});

// Criterion 5.
test('successive prunes accumulate carry, and rebuild stays exact', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    config(['bloodhound.announce_log.retention_days' => 30]);
    $this->cutoff = Carbon::now()->subDays(30)->subMinutes(250);
    $this->travelTo(Carbon::parse('2026-10-16 12:00:00')->subMinutes(250));
    prune($this);

    $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
    prune($this);

    expect(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    app(LedgerRebuilder::class)->rebuild();

    expect(pruneSnapshot($this))->toBe($before);
});

// Criterion 6. An opening balance is the oldest row an upgraded install has;
// it is the first thing retention reaches.
test('an opening balance can be pruned without losing it', function () {
    DB::table($this->user->getTable())->where('id', $this->user->id)->update(['uploaded' => 50_000, 'downloaded' => 8_000]);
    app(OpeningBalance::class)->record();
    AnnounceLog::query()->update(['created_at' => $this->cutoff->copy()->subDays(5)]);
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    prune($this);

    expect(AnnounceLog::query()->where('event', AnnounceLog::EVENT_OPENING_BALANCE)->count())->toBe(0)
        ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    app(LedgerRebuilder::class)->rebuild();

    expect(pruneSnapshot($this))->toBe($before);
});

// Criterion 7.
test('pruning introduces no chain-audit breaks', function () {
    straddlingHistory($this);
    app(LedgerAggregator::class)->run();

    prune($this);

    expect(app(LedgerAuditor::class)->audit()->chainBreaks)->toBe([]);
});

// The floor, unchanged: rows the aggregator has not consumed are never pruned,
// so nothing is carried for them either.
test('unaggregated aged rows are kept and carry nothing', function () {
    pruneRow($this, $this->user, $this->torrent, 'regular', 0, -60, up: 500);

    prune($this);

    expect(AnnounceLog::query()->count())->toBe(1)
        ->and(LedgerCarry::query()->count())->toBe(0);
});

// Nothing pairs rows, so no row is kept to protect another: every aged,
// aggregated row goes, stops included, and an announce that arrives after
// the prune is credited from its own row.
test('every aged, aggregated row is pruned, and a later announce is still credited', function () {
    pruneRow($this, $this->user, $this->torrent, 'regular', 0, -90, up: 100);
    pruneRow($this, $this->user, $this->torrent, 'regular', 0, -60, up: 200, seed: 1_800);
    pruneRow($this, $this->user, $this->torrent, 'stopped', 0, -30, up: 300, seed: 1_800);
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    prune($this);

    expect(AnnounceLog::query()->count())->toBe(0)
        ->and(app(LedgerAuditor::class)->reconcile()->hasDrift())->toBeFalse();

    app(LedgerRebuilder::class)->rebuild();
    expect(pruneSnapshot($this))->toBe($before);

    pruneRow($this, $this->user, $this->torrent, 'regular', 0, 20, up: 50, seed: 1_800);
    app(LedgerAggregator::class)->run();

    expect(pruneSnapshot($this)["user:{$this->user->id}"])->toBe([650, 0, 5_400, 0]);
});

test('a prune spread over many chunks carries the same as one', function () {
    foreach (range(1, 25) as $i) {
        pruneRow($this, $i % 2 ? $this->user : $this->other, $i % 3 ? $this->torrent : $this->second, 'regular', 0, -100 + $i, up: $i, seed: 60 * $i);
    }
    app(LedgerAggregator::class)->run();
    $before = pruneSnapshot($this);

    app(LedgerPruner::class)->prune($this->cutoff, PHP_INT_MAX, rowsPerChunk: 4);

    expect(AnnounceLog::query()->count())->toBe(0);

    app(LedgerRebuilder::class)->rebuild();

    expect(pruneSnapshot($this))->toBe($before);
});
