<?php

declare(strict_types=1);

// Job #112 CP2 (Spec #121): the aggregator derives seedtime and leechtime.
//
// Two things have to hold at once. Zero-delta rows — an idle seeder's
// announce, every `stopped` — must now reach the time calculation, because
// they are what seedtime is made of. And they must still move no bytes:
// the old skip was right about bytes, it was only wrong about time.
//
// The batch-boundary tests are the ones that matter most. A peer announces
// every ~1800s and a batch is 1000 rows, so in production a peer's two
// announces usually land in different batches, often different runs. A small
// fixture folded in one batch would pass whether or not that works.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'time@example.com',
        'password' => 'password',
    ]);

    $this->torrent = Torrent::create([
        'name' => 'Test Torrent',
        'info_hash' => str_repeat('a', 40),
        'size' => 5_000_000_000,
        'user_id' => $this->user->id,
    ]);

    $this->aggregator = app(LedgerAggregator::class);
    $this->t = Carbon::parse('2026-10-01 10:00:00');
});

/**
 * One announce, at a controlled time.
 */
function timedRow(
    $test,
    string $event,
    int $left,
    int $minutes,
    int $up = 0,
    int $down = 0,
    string $peerId = '-qB4210-aaaaaaaaaaaa',
    ?int $torrentId = null,
): AnnounceLog {
    $row = AnnounceLog::create([
        'user_id' => $test->user->id,
        'torrent_id' => $torrentId ?? $test->torrent->id,
        'peer_id' => $peerId,
        'event' => $event,
        'ip' => '10.0.0.1',
        'port' => 51413,
        'uploaded' => $up,
        'downloaded' => $down,
        'left' => $left,
        'upload_delta' => $up,
        'download_delta' => $down,
    ]);

    $row->forceFill(['created_at' => $test->t->copy()->addMinutes($minutes)])->save();

    return $row;
}

function userTimes($test): array
{
    $row = DB::table($test->user->getTable())->where('id', $test->user->id)->first();

    return ['seed' => (int) $row->seedtime, 'leech' => (int) $row->leechtime];
}

function torrentTimes($test, ?int $torrentId = null): array
{
    $row = TorrentUser::query()
        ->where('user_id', $test->user->id)
        ->where('torrent_id', $torrentId ?? $test->torrent->id)
        ->firstOrFail();

    return ['seed' => $row->seedtime, 'leech' => $row->leechtime];
}

describe('zero-delta rows', function () {
    // The rows the old skip discarded before anything could see them.
    test('an idle seeder accrues seedtime from announces that moved no bytes', function () {
        timedRow($this, 'started', 0, 0);
        timedRow($this, 'regular', 0, 30);
        timedRow($this, 'regular', 0, 60);

        $this->aggregator->run();

        expect(userTimes($this))->toBe(['seed' => 3600, 'leech' => 0])
            ->and(torrentTimes($this))->toBe(['seed' => 3600, 'leech' => 0]);
    });

    test('a stopped row still closes the interval before it', function () {
        timedRow($this, 'started', 0, 0);
        timedRow($this, 'stopped', 0, 30);

        $this->aggregator->run();

        expect(userTimes($this))->toBe(['seed' => 1800, 'leech' => 0]);
    });

    // The other half of criterion 10: loosening the skip for time must not
    // loosen it for bytes.
    test('zero-delta rows add no bytes', function () {
        timedRow($this, 'started', 0, 0);
        timedRow($this, 'regular', 0, 30, up: 5_000, down: 1_000);
        timedRow($this, 'regular', 0, 60);
        timedRow($this, 'stopped', 0, 90);

        $this->aggregator->run();

        $torrentUser = TorrentUser::query()->where('user_id', $this->user->id)->firstOrFail();

        expect($this->user->fresh()->uploaded)->toBe(5_000)
            ->and($this->user->fresh()->downloaded)->toBe(1_000)
            ->and($torrentUser->uploaded)->toBe(5_000)
            ->and($torrentUser->downloaded)->toBe(1_000);
    });

    // A lone announce that moved nothing has nothing to credit, so it must
    // not leave a torrent_user row behind — that is what the guard was for.
    test('an announce that moved neither bytes nor time writes no torrent_user row', function () {
        timedRow($this, 'started', 0, 0);

        $this->aggregator->run();

        expect(TorrentUser::query()->count())->toBe(0)
            ->and(userTimes($this))->toBe(['seed' => 0, 'leech' => 0]);
    });
});

describe('leechtime', function () {
    test('a peer with bytes left accrues leechtime, and the completing interval is leech', function () {
        timedRow($this, 'started', 1_000, 0);
        timedRow($this, 'regular', 500, 30);
        timedRow($this, 'completed', 0, 60);
        timedRow($this, 'regular', 0, 90);

        $this->aggregator->run();

        expect(userTimes($this))->toBe(['seed' => 1800, 'leech' => 3600])
            ->and(torrentTimes($this))->toBe(['seed' => 1800, 'leech' => 3600]);
    });
});

describe('batch boundaries', function () {
    // Batch size 1: every interval's two rows are in different batches.
    test('an interval split across batches is credited exactly once', function () {
        timedRow($this, 'started', 1_000, 0);
        timedRow($this, 'completed', 0, 30);
        timedRow($this, 'regular', 0, 60);
        timedRow($this, 'stopped', 0, 90);

        $this->aggregator->run(batchSize: 1);

        expect(userTimes($this))->toBe(['seed' => 3600, 'leech' => 1800])
            ->and(torrentTimes($this))->toBe(['seed' => 3600, 'leech' => 1800]);
    });

    // The production shape: the opening announce was folded by an earlier
    // run, long before the closing one existed. It sits below the watermark,
    // and the interval has to reach back to it.
    test('an interval whose opening row was folded by an earlier run is credited', function () {
        timedRow($this, 'started', 0, 0);
        $this->aggregator->run();

        timedRow($this, 'regular', 0, 30);
        $this->aggregator->run();

        expect(userTimes($this))->toBe(['seed' => 1800, 'leech' => 0]);
    });

    // The reach-back must find the peer's LATEST announce below the
    // watermark, not its first. Picking the first here credits the cap
    // (90 minutes back) instead of 1800s. MySQL 8.0.44 made exactly that
    // mistake on a correlated form of this lookup at 200k rows; this pins
    // the meaning at a size the suite can afford.
    test('reaching back finds the latest earlier announce, not the first', function () {
        timedRow($this, 'started', 0, 0);
        timedRow($this, 'regular', 0, 30);
        timedRow($this, 'regular', 0, 60);
        $this->aggregator->run();

        timedRow($this, 'regular', 0, 90);
        $this->aggregator->run();

        expect(userTimes($this))->toBe(['seed' => 5400, 'leech' => 0]);
    });

    test('other peers between a pair do not break it', function () {
        timedRow($this, 'started', 0, 0);
        timedRow($this, 'started', 0, 5, peerId: '-TR3000-bbbbbbbbbbbb');
        timedRow($this, 'regular', 0, 30);
        timedRow($this, 'regular', 0, 35, peerId: '-TR3000-bbbbbbbbbbbb');

        $this->aggregator->run(batchSize: 1);

        expect(userTimes($this))->toBe(['seed' => 3600, 'leech' => 0]);
    });

    test('folding in batches gives the same totals as folding at once', function () {
        $other = Torrent::create([
            'name' => 'Other', 'info_hash' => str_repeat('b', 40),
            'size' => 1_000, 'user_id' => $this->user->id,
        ]);

        timedRow($this, 'started', 1_000, 0);
        timedRow($this, 'started', 0, 1, torrentId: $other->id);
        timedRow($this, 'regular', 400, 30, down: 600);
        timedRow($this, 'regular', 0, 31, up: 50, torrentId: $other->id);
        timedRow($this, 'completed', 0, 60, down: 400);
        timedRow($this, 'stopped', 0, 61, torrentId: $other->id);
        timedRow($this, 'regular', 0, 400);

        $this->aggregator->run(batchSize: 2);
        $batched = [userTimes($this), torrentTimes($this), torrentTimes($this, $other->id)];

        DB::table($this->user->getTable())->update(['seedtime' => 0, 'leechtime' => 0, 'uploaded' => 0, 'downloaded' => 0]);
        DB::table('torrent_user')->delete();
        DB::table('ledger_cursors')->delete();

        $this->aggregator->run(batchSize: 1_000);
        $atOnce = [userTimes($this), torrentTimes($this), torrentTimes($this, $other->id)];

        // 400 - 60 = 340 minutes, capped at peer_expiry (3600s).
        expect($batched)->toBe($atOnce)
            ->and($atOnce[1])->toBe(['seed' => 3600, 'leech' => 3600])
            ->and($atOnce[2])->toBe(['seed' => 3600, 'leech' => 0]);
    });

    test('a second run over a caught-up ledger credits nothing more', function () {
        timedRow($this, 'started', 0, 0);
        timedRow($this, 'regular', 0, 30);

        $this->aggregator->run();
        $this->aggregator->run();

        expect(userTimes($this))->toBe(['seed' => 1800, 'leech' => 0]);
    });
});

test('leechtime is cast to an integer on the models that carry it', function () {
    timedRow($this, 'started', 1_000, 0);
    timedRow($this, 'regular', 1_000, 30);

    $this->aggregator->run();

    expect(TorrentUser::first()->leechtime)->toBe(1800)
        ->and($this->user->fresh()->leechtime)->toBe(1800);
});
