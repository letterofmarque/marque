<?php

declare(strict_types=1);

// CP6 of Build #92 (Spec #99 — the announce ledger).
//
// torrents.times_completed was a blind increment() on a client-supplied
// `event` parameter that nothing validated. peer_id is regenerated per client
// session, so a restart or a second machine inflated it — and anti-cheat's
// frequency check (keyed on torrent+peer_id) caught some but not all, making
// the inflation arbitrary as well as wrong.
//
// The hard part is not deduping, it is deciding what a second completion IS.
// Two cases that look identical from a single announce:
//
//   - five peer_ids within minutes: one download, client churn. ONE completion.
//   - January, then July after the user deleted and re-fetched: genuinely TWO.
//
// Time is the discriminator, and torrent_user.last_completed_at already holds
// what is needed. A completion within the cooldown of that user's last one is
// the same download session; beyond it, a real new one.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;
use Orchestra\Testbench\TestCase;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => false]);

    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'dedupe@example.com',
        'password' => 'password',
        'announce_key' => 'aaaabbbbccccddddeeeeffffgggghhhh',
    ]);

    $this->torrent = Torrent::create([
        'name' => 'Test Torrent',
        'info_hash' => str_repeat('a', 40),
        'size' => 4_000_000_000,
        'user_id' => $this->user->id,
    ]);
});

function completeAs(TestCase $test, string $peerId = '-qB4210-aaaaaaaaaaaa'): TestResponse
{
    $query = http_build_query([
        'info_hash' => hex2bin($test->torrent->info_hash),
        'peer_id' => $peerId,
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => $test->torrent->size,
        'left' => 0,
        'compact' => 1,
        'event' => 'completed',
    ]);

    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get("/announce/{$test->user->announce_key}?{$query}");
}

describe('one download session counts once', function () {
    test('a single completion counts one', function () {
        completeAs($this)->assertOk();

        expect($this->torrent->fresh()->times_completed)->toBe(1);
    });

    // The bug, gone. Five peer_ids is one client restarting, not five downloads.
    test('several peer ids in quick succession still count one', function () {
        foreach (['aaaa', 'bbbb', 'cccc', 'dddd', 'eeee'] as $suffix) {
            completeAs($this, "-qB4210-{$suffix}12345678")->assertOk();
        }

        expect($this->torrent->fresh()->times_completed)->toBe(1);
    });

    // The assertion the pinned test in 91f78dc was waiting to become.
    test('times_completed agrees with the per-user completion count', function () {
        foreach (['aaaa', 'bbbb', 'cccc'] as $suffix) {
            completeAs($this, "-qB4210-{$suffix}12345678")->assertOk();
        }

        // Cast: MySQL returns SUM() as a string, SQLite and Postgres as an
        // integer, and toBe() is strict. The sibling assertions in
        // LedgerAggregationTest already do this; this one was missed.
        expect($this->torrent->fresh()->times_completed)
            ->toBe((int) TorrentUser::sum('times_completed'));
    });

    test('the per-user row also counts one', function () {
        foreach (['aaaa', 'bbbb', 'cccc'] as $suffix) {
            completeAs($this, "-qB4210-{$suffix}12345678")->assertOk();
        }

        expect(TorrentUser::first()->times_completed)->toBe(1);
    });
});

describe('a genuine redownload counts again', function () {
    // Dan's corrupt-quarter case: the third file of four was bad, the user
    // re-fetched it months later, and the client fired `completed` again. That
    // is a real second completion — and it says nothing about bytes, which is
    // why the two are tracked separately.
    test('a completion beyond the cooldown counts as a second', function () {
        Carbon::setTestNow('2026-01-01 10:00:00');
        completeAs($this)->assertOk();

        Carbon::setTestNow('2026-07-01 10:00:00');
        completeAs($this)->assertOk();

        Carbon::setTestNow();

        expect($this->torrent->fresh()->times_completed)->toBe(2);
    });

    test('the first completion date survives it', function () {
        Carbon::setTestNow('2026-01-01 10:00:00');
        completeAs($this)->assertOk();

        Carbon::setTestNow('2026-07-01 10:00:00');
        completeAs($this)->assertOk();

        Carbon::setTestNow();

        $row = TorrentUser::first();

        expect($row->first_completed_at->toDateTimeString())->toBe('2026-01-01 10:00:00')
            ->and($row->last_completed_at->toDateTimeString())->toBe('2026-07-01 10:00:00')
            ->and($row->times_completed)->toBe(2);
    });

    test('the cooldown boundary is configurable', function () {
        config(['bloodhound.completion_cooldown' => 3600]);

        Carbon::setTestNow('2026-01-01 10:00:00');
        completeAs($this)->assertOk();

        // Inside the window — same session.
        Carbon::setTestNow('2026-01-01 10:30:00');
        completeAs($this)->assertOk();

        expect($this->torrent->fresh()->times_completed)->toBe(1);

        // Beyond it — a new one.
        Carbon::setTestNow('2026-01-01 12:00:00');
        completeAs($this)->assertOk();

        Carbon::setTestNow();

        expect($this->torrent->fresh()->times_completed)->toBe(2);
    });
});

describe('different users are counted independently', function () {
    test('two users completing gives two', function () {
        $other = TestUser::create([
            'name' => 'Other', 'email' => 'other@example.com', 'password' => 'p',
            'announce_key' => str_repeat('c', 32),
        ]);

        completeAs($this)->assertOk();

        $query = http_build_query([
            'info_hash' => hex2bin($this->torrent->info_hash),
            'peer_id' => '-qB4210-zzzzzzzzzzzz',
            'port' => 51413, 'uploaded' => 0,
            'downloaded' => $this->torrent->size, 'left' => 0,
            'compact' => 1, 'event' => 'completed',
        ]);

        $this->withoutMiddleware(BlockBrowsers::class)
            ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
            ->get("/announce/{$other->announce_key}?{$query}")
            ->assertOk();

        expect($this->torrent->fresh()->times_completed)->toBe(2)
            ->and(TorrentUser::count())->toBe(2);
    });
});
