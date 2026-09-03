<?php

declare(strict_types=1);

// CP5 of Build #92 (Spec #99 — the announce ledger). The cutover.
//
// Closes failure mode 2: byte counts stop travelling as job payloads.
//
// UpdateUserStats carried the delta inside the job. A lost job was a lost
// credit, and because Redis had already advanced the baseline it could not be
// re-derived — and on a typical Laravel box the queue IS Redis, so one restart
// lost both the pending credit and the baseline behind it.
//
// Aggregation now reads ledger rows past a stored watermark and advances the
// cursor in the same transaction as the projection write. Crash-safety comes
// from the transaction boundary, not from queue delivery guarantees, so the
// queue can lose everything and nothing is lost — it just runs late.

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'agg@example.com',
        'password' => 'password',
        'announce_key' => 'aaaabbbbccccddddeeeeffffgggghhhh',
    ]);

    $this->torrent = Torrent::create([
        'name' => 'Test Torrent',
        'info_hash' => str_repeat('a', 40),
        'size' => 5_000_000_000,
        'user_id' => $this->user->id,
    ]);

    $this->aggregator = app(LedgerAggregator::class);

    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => false]);
});

function announceForAggregation($test, int $uploaded = 0): void
{
    $query = http_build_query([
        'info_hash' => hex2bin($test->torrent->info_hash),
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'port' => 51413,
        'uploaded' => $uploaded,
        'downloaded' => 0,
        'left' => 0,
        'compact' => 1,
    ]);

    $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get("/announce/{$test->user->announce_key}?{$query}")
        ->assertOk();
}

function ledgerRow(int $userId, int $torrentId, int $up, int $down, string $event = 'regular'): AnnounceLog
{
    return AnnounceLog::create([
        'user_id' => $userId,
        'torrent_id' => $torrentId,
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'event' => $event,
        'ip' => '10.0.0.1',
        'port' => 51413,
        'uploaded' => $up,
        'downloaded' => $down,
        'left' => 0,
        'upload_delta' => $up,
        'download_delta' => $down,
        'prior_up' => 0,
        'prior_down' => 0,
    ]);
}

describe('folding ledger rows into projections', function () {
    test('sums deltas onto the user total', function () {
        ledgerRow($this->user->id, $this->torrent->id, 1_000, 2_000);
        ledgerRow($this->user->id, $this->torrent->id, 3_000, 4_000);

        $this->aggregator->run();

        expect($this->user->fresh()->uploaded)->toBe(4_000)
            ->and($this->user->fresh()->downloaded)->toBe(6_000);
    });

    test('sums deltas onto the per-torrent row', function () {
        ledgerRow($this->user->id, $this->torrent->id, 1_000, 2_000);
        ledgerRow($this->user->id, $this->torrent->id, 3_000, 4_000);

        $this->aggregator->run();

        $row = TorrentUser::first();

        expect($row->uploaded)->toBe(4_000)
            ->and($row->downloaded)->toBe(6_000);
    });

    test('keeps per-torrent totals separate', function () {
        $other = Torrent::create([
            'name' => 'Other', 'info_hash' => str_repeat('b', 40),
            'size' => 1_000, 'user_id' => $this->user->id,
        ]);

        ledgerRow($this->user->id, $this->torrent->id, 1_000, 0);
        ledgerRow($this->user->id, $other->id, 5_000, 0);

        $this->aggregator->run();

        expect(TorrentUser::where('torrent_id', $this->torrent->id)->first()->uploaded)->toBe(1_000)
            ->and(TorrentUser::where('torrent_id', $other->id)->first()->uploaded)->toBe(5_000)
            ->and($this->user->fresh()->uploaded)->toBe(6_000);
    });

    // The property that makes users.uploaded verifiable rather than an
    // accumulator nothing can check.
    test('the user total equals the sum of its per-torrent rows', function () {
        $other = Torrent::create([
            'name' => 'Other', 'info_hash' => str_repeat('b', 40),
            'size' => 1_000, 'user_id' => $this->user->id,
        ]);

        ledgerRow($this->user->id, $this->torrent->id, 1_000, 500);
        ledgerRow($this->user->id, $other->id, 5_000, 250);

        $this->aggregator->run();

        expect($this->user->fresh()->uploaded)->toBe((int) TorrentUser::sum('uploaded'))
            ->and($this->user->fresh()->downloaded)->toBe((int) TorrentUser::sum('downloaded'));
    });
});

describe('the cursor', function () {
    test('advances past the rows it folded', function () {
        $last = ledgerRow($this->user->id, $this->torrent->id, 1_000, 0);

        $this->aggregator->run();

        expect(LedgerCursor::positionFor(LedgerAggregator::STREAM))->toBe($last->id);
    });

    // Idempotence by construction: re-running must not re-credit.
    test('a second run over the same rows credits nothing further', function () {
        ledgerRow($this->user->id, $this->torrent->id, 1_000, 2_000);

        $this->aggregator->run();
        $this->aggregator->run();
        $this->aggregator->run();

        expect($this->user->fresh()->uploaded)->toBe(1_000);
    });

    test('only new rows are folded on a later run', function () {
        ledgerRow($this->user->id, $this->torrent->id, 1_000, 0);
        $this->aggregator->run();

        ledgerRow($this->user->id, $this->torrent->id, 500, 0);
        $this->aggregator->run();

        expect($this->user->fresh()->uploaded)->toBe(1_500);
    });

    test('an empty ledger leaves the cursor where it was', function () {
        $this->aggregator->run();

        expect(LedgerCursor::positionFor(LedgerAggregator::STREAM))->toBe(0);
    });

    // Rows with no delta still move the cursor — otherwise a run of stopped
    // announces would wedge it and every later batch would re-scan them.
    test('zero-delta rows still advance the cursor', function () {
        $last = ledgerRow($this->user->id, $this->torrent->id, 0, 0, 'stopped');

        $this->aggregator->run();

        expect(LedgerCursor::positionFor(LedgerAggregator::STREAM))->toBe($last->id);
    });
});

describe('failure', function () {
    // The test this checkpoint exists for. A worker dying mid-batch must leave
    // the cursor unmoved so the batch is redone, not half-applied.
    test('a batch that dies mid-transaction credits nothing and does not advance', function () {
        ledgerRow($this->user->id, $this->torrent->id, 1_000, 2_000);

        // Fail after the projections are written but before commit.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'ledger_cursors')
                && (str_starts_with(trim($query->sql), 'insert') || str_starts_with(trim($query->sql), 'update'))) {
                throw new RuntimeException('worker died mid-batch');
            }
        });

        try {
            $this->aggregator->run();
        } catch (RuntimeException) {
            // expected
        }

        expect($this->user->fresh()->uploaded)->toBe(0)
            ->and(TorrentUser::count())->toBe(0)
            ->and(LedgerCursor::positionFor(LedgerAggregator::STREAM))->toBe(0);
    });

    test('the redone batch credits exactly once', function () {
        ledgerRow($this->user->id, $this->torrent->id, 1_000, 2_000);

        $die = true;
        DB::listen(function ($query) use (&$die) {
            if ($die && str_contains($query->sql, 'ledger_cursors')
                && (str_starts_with(trim($query->sql), 'insert') || str_starts_with(trim($query->sql), 'update'))) {
                $die = false;
                throw new RuntimeException('worker died mid-batch');
            }
        });

        try {
            $this->aggregator->run();
        } catch (RuntimeException) {
            // expected
        }

        $this->aggregator->run();

        expect($this->user->fresh()->uploaded)->toBe(1_000);
    });
});

describe('batching', function () {
    test('folds more rows than fit in one batch', function () {
        for ($i = 0; $i < 250; $i++) {
            ledgerRow($this->user->id, $this->torrent->id, 10, 0);
        }

        $this->aggregator->run(batchSize: 100);

        expect($this->user->fresh()->uploaded)->toBe(2_500);
    });
});

// The second test this checkpoint turns on: the queue is a scheduling
// mechanism, not a data path. Losing every job it holds must cost nothing.
describe('the queue is not a data path', function () {
    test('an announce dispatches no stats job at all', function () {
        Queue::fake();

        announceForAggregation($this);

        Queue::assertNothingPushed();
    });

    test('totals still reach the ledger figure with every job dropped', function () {
        Queue::fake();

        announceForAggregation($this, uploaded: 0);
        announceForAggregation($this, uploaded: 4_000);

        // Nothing the queue was holding ever runs.
        expect($this->user->fresh()->uploaded)->toBe(0);

        $this->artisan('bloodhound:aggregate-ledger')->assertSuccessful();

        expect($this->user->fresh()->uploaded)->toBe(4_000);
    });

    test('the scheduled tick is registered', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'bloodhound:aggregate-ledger'));

        expect($events)->toHaveCount(1);
    });
});
