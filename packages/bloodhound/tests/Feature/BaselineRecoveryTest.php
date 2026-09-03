<?php

declare(strict_types=1);

// CP3 of Build #92 (Spec #99 — the announce ledger).
//
// Failure mode 1: the baseline a delta is diffed against lives in Redis. Lose
// Redis and the next announce has nothing to diff against, so it credits zero
// — silently, with no error and nothing to detect it by. A user at 10GB who
// announces 12GB after a restart gets nothing for those 2GB, ever.
//
// The baseline is also in the ledger, so a Redis miss can recover it instead
// of guessing. After this, correctness no longer depends on Redis persistence
// being configured correctly, which matters because a package cannot rely on
// anyone's deployment config being right.

use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;
use Orchestra\Testbench\TestCase;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

    // Several announces back to back, which a real client would not do.
    config(['bloodhound.anti_cheat.enabled' => false]);

    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'baseline@example.com',
        'password' => 'password',
        'announce_key' => 'aaaabbbbccccddddeeeeffffgggghhhh',
    ]);

    $this->torrent = Torrent::create([
        'name' => 'Test Torrent',
        'info_hash' => str_repeat('a', 40),
        'size' => 100_000_000_000,
        'user_id' => $this->user->id,
    ]);
});

function recoveryUrl(TestUser $user, Torrent $torrent, array $params = []): string
{
    $query = http_build_query(array_merge([
        'info_hash' => hex2bin($torrent->info_hash),
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'compact' => 1,
    ], $params));

    return "/announce/{$user->announce_key}?{$query}";
}

function recoveryAnnounce(TestCase $test, string $url): TestResponse
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get($url);
}

function flushPeerState(): void
{
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
}

describe('losing Redis mid-session', function () {
    // The test this checkpoint exists for.
    test('credits the bytes that spanned the outage instead of zero', function () {
        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'event' => 'started', 'uploaded' => 10_000_000_000, 'downloaded' => 0,
        ]))->assertOk();

        flushPeerState();

        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'uploaded' => 12_000_000_000, 'downloaded' => 0,
        ]))->assertOk();

        $row = AnnounceLog::orderByDesc('id')->first();

        expect($row->prior_up)->toBe(10_000_000_000)
            ->and($row->upload_delta)->toBe(2_000_000_000);
    });

    // Since CP5 the announce path only writes the ledger; totals are folded
    // from it by the aggregator. So the credit is asserted after aggregation,
    // not immediately after the announce.
    test('the user is credited those bytes on their total', function () {
        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'event' => 'started', 'uploaded' => 10_000_000_000,
        ]))->assertOk();

        app(LedgerAggregator::class)->run();
        $afterFirst = $this->user->fresh()->uploaded;

        flushPeerState();

        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'uploaded' => 12_000_000_000,
        ]))->assertOk();

        app(LedgerAggregator::class)->run();

        expect($this->user->fresh()->uploaded - $afterFirst)->toBe(2_000_000_000);
    });

    // Without the fallback this is what happened: no baseline, so no delta.
    test('does not silently credit zero', function () {
        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'event' => 'started', 'uploaded' => 10_000_000_000,
        ]))->assertOk();

        flushPeerState();

        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'uploaded' => 12_000_000_000,
        ]))->assertOk();

        expect(AnnounceLog::orderByDesc('id')->first()->upload_delta)->not->toBe(0);
    });

    test('the ledger chain stays unbroken across the outage', function () {
        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'event' => 'started', 'uploaded' => 10_000_000_000,
        ]))->assertOk();

        flushPeerState();

        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'uploaded' => 12_000_000_000,
        ]))->assertOk();

        $rows = AnnounceLog::orderBy('id')->get();

        expect($rows)->toHaveCount(2)
            ->and($rows[1]->prior_up)->toBe($rows[0]->uploaded);
    });
});

describe('the fallback is scoped correctly', function () {
    // A genuinely new peer has no ledger history either — null is right, and
    // the fallback must not invent a baseline from some other peer's rows.
    test('a first-ever announce still records a null baseline', function () {
        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'event' => 'started', 'uploaded' => 500,
        ]))->assertOk();

        expect(AnnounceLog::first()->prior_up)->toBeNull();
    });

    test('a different peer_id does not inherit another peer session baseline', function () {
        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'event' => 'started', 'uploaded' => 10_000_000_000,
        ]))->assertOk();

        flushPeerState();

        // A client restart regenerates peer_id. Its cumulative counters reset
        // too, so diffing against the OLD peer's total would be wrong — and
        // with max(0, ...) would silently credit nothing anyway.
        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'peer_id' => '-qB4210-bbbbbbbbbbbb', 'event' => 'started', 'uploaded' => 0,
        ]))->assertOk();

        $row = AnnounceLog::orderByDesc('id')->first();

        expect($row->prior_up)->toBeNull()
            ->and($row->upload_delta)->toBe(0);
    });

    test('a different torrent does not inherit this torrent baseline', function () {
        $other = Torrent::create([
            'name' => 'Other', 'info_hash' => str_repeat('b', 40),
            'size' => 1_000_000, 'user_id' => $this->user->id,
        ]);

        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'event' => 'started', 'uploaded' => 10_000_000_000,
        ]))->assertOk();

        flushPeerState();

        recoveryAnnounce($this, recoveryUrl($this->user, $other, [
            'event' => 'started', 'uploaded' => 0,
        ]))->assertOk();

        $row = AnnounceLog::orderByDesc('id')->first();

        expect($row->torrent_id)->toBe($other->id)
            ->and($row->prior_up)->toBeNull();
    });

    // Redis stays the fast path — the ledger is only consulted on a miss.
    test('a warm Redis session does not read the ledger for its baseline', function () {
        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'event' => 'started', 'uploaded' => 1_000,
        ]))->assertOk();

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'announce_log') && str_starts_with(trim($q->sql), 'select')) {
                $queries++;
            }
        });

        recoveryAnnounce($this, recoveryUrl($this->user, $this->torrent, [
            'uploaded' => 5_000,
        ]))->assertOk();

        expect($queries)->toBe(0);
    });
});
