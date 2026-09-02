<?php

declare(strict_types=1);

// Spec #98: full-detail, off-by-default announce history. Tests go through
// the real /announce/{key} route (matching TrackerRoutesTest's style) rather
// than calling AnnounceService directly, since the thing under test is the
// actual wiring from a real request through to a logged row.

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Tests\TestCase;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;

function announceGet(TestCase $test, string $url): \Illuminate\Testing\TestResponse
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get($url);
}

beforeEach(function () {
    // AntiCheatService::checkAnnounceFrequency() (min_announce_gap, default
    // 60s) is real Redis state keyed by torrent_id:peer_id, not reset by
    // RefreshDatabase between tests — and every test in this file reuses the
    // same literal peer_id. Flush so each test starts with a clean slate;
    // scoped to this file only, not a package-wide test-isolation fix.
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'announcelog@example.com',
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

function announceUrl(TestUser $user, Torrent $torrent, array $params = []): string
{
    $default = [
        'info_hash' => hex2bin($torrent->info_hash),
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'port' => 51413, // NOT 6881 — that's in threepio's default blacklisted_ports
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => $torrent->size,
        'compact' => 1,
    ];

    $query = http_build_query(array_merge($default, $params));

    return "/announce/{$user->announce_key}?{$query}";
}

describe('announce log disabled (the default)', function () {
    it('dispatches no LogAnnounce job at all', function () {
        Queue::fake();

        announceGet($this, announceUrl($this->user, $this->torrent, ['event' => 'started']));

        Queue::assertNothingPushed();
    });

    it('writes no rows', function () {
        config(['bloodhound.queue.enabled' => false]);

        announceGet($this, announceUrl($this->user, $this->torrent, ['event' => 'started']));

        expect(AnnounceLog::count())->toBe(0);
    });
});

describe('announce log enabled', function () {
    beforeEach(function () {
        config(['bloodhound.announce_log.enabled' => true]);
        config(['bloodhound.queue.enabled' => false]); // dispatchSync-equivalent for the test
    });

    it('logs a full announce lifecycle: started, regular, completed, stopped', function () {
        // This test fires 4 announces back-to-back, synthetically — a real
        // client respects min_announce_gap between them. Disabled here since
        // the thing under test is delta computation across the lifecycle,
        // not anti-cheat frequency behaviour (covered separately above).
        config(['bloodhound.anti_cheat.enabled' => false]);

        announceGet($this, announceUrl($this->user, $this->torrent, [
            'event' => 'started',
            'uploaded' => 0,
            'downloaded' => 0,
        ]));

        announceGet($this, announceUrl($this->user, $this->torrent, [
            'uploaded' => 1000,
            'downloaded' => 500,
        ]));

        announceGet($this, announceUrl($this->user, $this->torrent, [
            'event' => 'completed',
            'uploaded' => 2000,
            'downloaded' => 1_000_000,
            'left' => 0,
        ]));

        announceGet($this, announceUrl($this->user, $this->torrent, [
            'event' => 'stopped',
            'uploaded' => 2000,
            'downloaded' => 1_000_000,
            'left' => 0,
        ]));

        $rows = AnnounceLog::orderBy('id')->get();

        expect($rows)->toHaveCount(4);
        expect($rows[0]->event)->toBe('started');
        expect($rows[1]->event)->toBe('regular');
        expect($rows[1]->upload_delta)->toBe(1000);
        expect($rows[1]->download_delta)->toBe(500);
        expect($rows[2]->event)->toBe('completed');
        expect($rows[2]->upload_delta)->toBe(1000); // 2000 - 1000
        expect($rows[3]->event)->toBe('stopped');

        expect($rows[0]->user_id)->toBe($this->user->id);
        expect($rows[0]->torrent_id)->toBe($this->torrent->id);
    });

    it('records cumulative totals as reported, alongside the computed delta', function () {
        announceGet($this, announceUrl($this->user, $this->torrent, [
            'event' => 'started',
            'uploaded' => 5000,
            'downloaded' => 2000,
        ]));

        $row = AnnounceLog::sole();

        expect($row->uploaded)->toBe(5000);
        expect($row->downloaded)->toBe(2000);
    });

    it('flags an announce that fails an anti-cheat check, with the reason', function () {
        config(['bloodhound.anti_cheat.enabled' => true]);
        config(['threepio.blacklisted_ports' => [6881]]);

        announceGet($this, announceUrl($this->user, $this->torrent, [
            'event' => 'started',
            'port' => 6881, // blacklisted
        ]));

        $row = AnnounceLog::sole();

        expect($row->anti_cheat_flagged)->toBeTrue();
        expect($row->anti_cheat_reason)->toContain('blacklisted');
    });

    it('does not flag a clean announce', function () {
        announceGet($this, announceUrl($this->user, $this->torrent, ['event' => 'started']));

        $row = AnnounceLog::sole();

        expect($row->anti_cheat_flagged)->toBeFalse();
        expect($row->anti_cheat_reason)->toBeNull();
    });
});

describe('the existing Redis suspicious list', function () {
    it('still fires unconditionally, regardless of announce_log.enabled', function () {
        // Spec #98 Decision: AntiCheatService::flagSuspicious() is NOT gated
        // by the new feature — it must keep working for an operator who
        // hasn't opted into announce_log at all. checkPort() rejects before
        // ever calling dispatchCheatEvent()/flagSuspicious() (confirmed by
        // reading AntiCheatService::check()), so this needs a violation that
        // actually reaches that call — data sanity (downloaded > torrent
        // size) does.
        config(['bloodhound.announce_log.enabled' => false]);
        config(['bloodhound.anti_cheat.enabled' => true]);

        announceGet($this, announceUrl($this->user, $this->torrent, [
            'event' => 'started',
            'downloaded' => $this->torrent->size * 2, // impossible — data sanity violation
            'left' => 0,
        ]));

        $antiCheat = app(\Marque\Bloodhound\Services\AntiCheatService::class);
        $suspicious = $antiCheat->getSuspiciousActivity();

        expect($suspicious)->not->toBeEmpty();
    });
});
