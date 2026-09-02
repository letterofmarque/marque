<?php

declare(strict_types=1);

// The reaper is the reason the seeders/leechers columns can be trusted, and
// the reason the `visible` flag they replace could not be. `visible` had a
// write path (set true on a seeder announce) and no invalidation path, so it
// could only ever go true and told you nothing. These tests are specifically
// about the invalidation path: a torrent whose peers vanished without a
// stopped announce must end up at zero.

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

    $this->peers = app(PeerService::class);

    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'swarm@example.com',
        'password' => 'password',
        'announce_key' => 'aaaabbbbccccddddeeeeffffgggghhhh',
    ]);
});

// Bloodhound's TestUser has no factory, so torrents are built explicitly here
// the same way the rest of this package's tests build them.
function makeTorrent(int $seeders = 0, int $leechers = 0, ?string $hash = null): Torrent
{
    $torrent = Torrent::create([
        'name' => 'Test Torrent',
        'info_hash' => $hash ?? str_repeat(dechex(random_int(1, 15)), 40),
        'size' => 1_000_000,
        'user_id' => test()->user->id,
    ]);

    if ($seeders !== 0 || $leechers !== 0) {
        $torrent->forceFill(['seeders' => $seeders, 'leechers' => $leechers])->save();
    }

    return $torrent->fresh();
}

/**
 * Backdate a peer's last_action so it reads as expired, simulating a client
 * that vanished without sending 'stopped'.
 */
function expirePeer(int $torrentId, string $peerId): void
{
    $redis = Redis::connection(config('threepio.redis.connection', 'default'));
    $key = config('threepio.redis.prefix', 'marque:')."peers:{$torrentId}";

    $peer = json_decode($redis->hget($key, $peerId), true);
    // Far enough back to be expired under any sane configured window.
    $peer['last_action'] = time() - 86_400;

    $redis->hset($key, $peerId, json_encode($peer));
}

function announcePeer(int $torrentId, string $peerId, bool $isSeeder): void
{
    test()->peers->upsertPeer(
        torrentId: $torrentId,
        peerId: $peerId,
        userId: 1,
        ip: '10.0.0.1',
        port: 51413,
        uploaded: 0,
        downloaded: 0,
        left: $isSeeder ? 0 : 1_000,
        userAgent: 'test',
        isSeeder: $isSeeder,
    );
}

test('writes live peer counts onto a torrent that has none recorded', function () {
    $torrent = makeTorrent();

    announcePeer($torrent->id, '-qB4210-aaaaaaaaaaaa', true);
    announcePeer($torrent->id, '-qB4210-bbbbbbbbbbbb', false);

    $this->artisan('bloodhound:sync-swarm-counts')->assertSuccessful();

    expect($torrent->fresh()->seeders)->toBe(1)
        ->and($torrent->fresh()->leechers)->toBe(1);
});

// The case that rotted `visible`: nothing announces again, so no announce-path
// write ever corrects the row. Only a sweep can.
test('zeroes a torrent whose peers expired without a stopped announce', function () {
    $torrent = makeTorrent(seeders: 4, leechers: 2);

    announcePeer($torrent->id, '-qB4210-cccccccccccc', true);

    // Age the peer past expiry without sending 'stopped' — rewrite its
    // last_action into the past rather than shortening the window, because
    // expiry is a strict `now - last_action > expiry` and a peer created this
    // same second is not yet expired even at expiry 0.
    expirePeer($torrent->id, '-qB4210-cccccccccccc');

    $this->artisan('bloodhound:sync-swarm-counts')->assertSuccessful();

    expect($torrent->fresh()->seeders)->toBe(0)
        ->and($torrent->fresh()->leechers)->toBe(0);
});

test('zeroes a torrent that never had any peers but has stale counts', function () {
    $torrent = makeTorrent(seeders: 9, leechers: 9);

    $this->artisan('bloodhound:sync-swarm-counts')->assertSuccessful();

    expect($torrent->fresh()->seeders)->toBe(0)
        ->and($torrent->fresh()->leechers)->toBe(0);
});

test('leaves an already-correct torrent alone', function () {
    $torrent = makeTorrent();

    $before = $torrent->updated_at;

    $this->artisan('bloodhound:sync-swarm-counts')->assertSuccessful();

    expect($torrent->fresh()->updated_at->eq($before))->toBeTrue();
});

test('processes every torrent, not just the first chunk', function () {
    foreach (range(1, 5) as $i) {
        makeTorrent(seeders: 7, hash: str_pad((string) $i, 40, '0', STR_PAD_LEFT));
    }

    $this->artisan('bloodhound:sync-swarm-counts --chunk=2')->assertSuccessful();

    expect(Torrent::where('seeders', '>', 0)->count())->toBe(0);
});

test('is scheduled hourly', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'bloodhound:sync-swarm-counts'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});
