<?php

declare(strict_types=1);

// CP2 of Build #92 (Spec #99 — the announce ledger).
//
// The durable commit point. The ledger row must exist before the response is
// returned, not after a worker gets round to it — everything downstream
// (reconciliation, rebuild, the Redis-miss fallback) assumes the row is there
// the moment the announce completes.

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;
use Orchestra\Testbench\TestCase;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

    // These fire several announces back to back, which a real client would not.
    config(['bloodhound.anti_cheat.enabled' => false]);

    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'ledger@example.com',
        'password' => 'password',
        'announce_key' => 'aaaabbbbccccddddeeeeffffgggghhhh',
    ]);

    $this->torrent = Torrent::create([
        'name' => 'Test Torrent',
        'info_hash' => str_repeat('a', 40),
        'size' => 1_000_000,
        'user_id' => $this->user->id,
    ]);
});

function ledgerUrl(TestUser $user, Torrent $torrent, array $params = []): string
{
    $query = http_build_query(array_merge([
        'info_hash' => hex2bin($torrent->info_hash),
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => $torrent->size,
        'compact' => 1,
    ], $params));

    return "/announce/{$user->announce_key}?{$query}";
}

function ledgerAnnounce(TestCase $test, string $url): TestResponse
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get($url);
}

describe('the write is synchronous', function () {
    // The point of the checkpoint: with the queue faked and nothing processed,
    // the row still has to be there. If this fails, the ledger is only
    // reachable via a worker and every durability claim built on it is false.
    test('the row exists with the queue faked and nothing processed', function () {
        Queue::fake();

        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, ['event' => 'started']))
            ->assertOk();

        expect(AnnounceLog::count())->toBe(1);
    });

    test('no LogAnnounce job is dispatched any more', function () {
        Queue::fake();

        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, ['event' => 'started']))
            ->assertOk();

        Queue::assertNothingPushed();
    });

    test('disabling the queue changes nothing about the ledger', function () {
        config(['bloodhound.queue.enabled' => false]);

        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, ['event' => 'started']))
            ->assertOk();

        expect(AnnounceLog::count())->toBe(1);
    });
});

describe('prior counters are recorded', function () {
    // A peer's first announce has nothing to diff against. Null is the honest
    // answer; zero would claim a baseline that was never observed.
    test('a first announce records null priors and zero deltas', function () {
        Queue::fake();

        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, [
            'event' => 'started',
            'uploaded' => 0,
            'downloaded' => 0,
        ]))->assertOk();

        $row = AnnounceLog::first();

        expect($row->prior_up)->toBeNull()
            ->and($row->prior_down)->toBeNull()
            ->and($row->upload_delta)->toBe(0)
            ->and($row->download_delta)->toBe(0);
    });

    test('a subsequent announce records the baseline it diffed against', function () {
        Queue::fake();

        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, [
            'event' => 'started',
            'uploaded' => 1_000,
            'downloaded' => 2_000,
        ]))->assertOk();

        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, [
            'uploaded' => 5_000,
            'downloaded' => 9_000,
        ]))->assertOk();

        $row = AnnounceLog::orderByDesc('id')->first();

        expect($row->prior_up)->toBe(1_000)
            ->and($row->prior_down)->toBe(2_000)
            ->and($row->upload_delta)->toBe(4_000)
            ->and($row->download_delta)->toBe(7_000);
    });

    // The property CP7's audit depends on. Without it a row cannot be checked
    // in isolation and a wrong baseline leaves no trace.
    test('every row can verify its own arithmetic', function () {
        Queue::fake();

        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, [
            'event' => 'started', 'uploaded' => 1_000, 'downloaded' => 2_000,
        ]))->assertOk();

        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, [
            'uploaded' => 5_000, 'downloaded' => 9_000,
        ]))->assertOk();

        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, [
            'uploaded' => 12_000, 'downloaded' => 20_000,
        ]))->assertOk();

        $rows = AnnounceLog::orderBy('id')->get()->filter(fn ($r) => $r->prior_up !== null);

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            expect($row->uploaded - $row->prior_up)->toBe($row->upload_delta)
                ->and($row->downloaded - $row->prior_down)->toBe($row->download_delta);
        }
    });

    // The chain property: each row's prior is the previous row's reported
    // value for the same peer. A break in this chain is the signature of a
    // lost baseline, which is what makes a Redis outage detectable after the
    // fact rather than silent.
    test('priors chain to the previous row for the same peer', function () {
        Queue::fake();

        foreach ([[1_000, 2_000], [5_000, 9_000], [12_000, 20_000]] as $i => [$up, $down]) {
            ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, array_merge(
                $i === 0 ? ['event' => 'started'] : [],
                ['uploaded' => $up, 'downloaded' => $down],
            )))->assertOk();
        }

        $rows = AnnounceLog::orderBy('id')->get();

        // Without this the loop below iterates nothing and the test passes
        // vacuously — which it did on the red run.
        expect($rows)->toHaveCount(3);

        $previous = null;

        foreach ($rows as $row) {
            if ($previous !== null) {
                expect($row->prior_up)->toBe($previous->uploaded)
                    ->and($row->prior_down)->toBe($previous->downloaded);
            }

            $previous = $row;
        }
    });
});

describe('a rejected announce still writes a row', function () {
    // Spec #98 established that a rejected announce is itself history worth
    // keeping. It has no delta and no baseline, because upsertPeer never ran.
    test('with null priors, since no diff was performed', function () {
        config(['bloodhound.anti_cheat.enabled' => true]);
        Queue::fake();

        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent, ['event' => 'started']))
            ->assertOk();

        // Immediately again — trips the frequency check.
        ledgerAnnounce($this, ledgerUrl($this->user, $this->torrent))->assertOk();

        $row = AnnounceLog::orderByDesc('id')->first();

        expect($row->anti_cheat_flagged)->toBeTrue()
            ->and($row->prior_up)->toBeNull()
            ->and($row->prior_down)->toBeNull();
    });
});
