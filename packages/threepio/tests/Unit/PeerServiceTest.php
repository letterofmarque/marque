<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Marque\Threepio\Services\PeerService;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    $this->peers = app(PeerService::class);
});

function addPeer(PeerService $peers, int $torrentId, string $peerId, bool $isSeeder = true): void
{
    $peers->upsertPeer(
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

function backdatePeer(int $torrentId, string $peerId): void
{
    $redis = Redis::connection(config('threepio.redis.connection', 'default'));
    $key = config('threepio.redis.prefix', 'marque:')."peers:{$torrentId}";

    $peer = json_decode($redis->hget($key, $peerId), true);
    $peer['last_action'] = time() - 86_400;

    $redis->hset($key, $peerId, json_encode($peer));
}

test('counts seeders and leechers as peers announce', function () {
    addPeer($this->peers, 1, '-qB4210-aaaaaaaaaaaa', isSeeder: true);
    addPeer($this->peers, 1, '-qB4210-bbbbbbbbbbbb', isSeeder: false);

    expect($this->peers->getSeeders(1))->toBe(1)
        ->and($this->peers->getLeechers(1))->toBe(1);
});

test('removing a peer decrements the right counter', function () {
    addPeer($this->peers, 1, '-qB4210-aaaaaaaaaaaa', isSeeder: true);

    $this->peers->removePeer(1, '-qB4210-aaaaaaaaaaaa');

    expect($this->peers->getSeeders(1))->toBe(0);
});

// Regression: removePeer() used to read the peer via getPeer(), which deletes
// an expired peer by calling removePeer() — so removing an expired peer
// recursed until the process segfaulted. Nothing hit it until a sweep that
// removes expired peers existed.
test('removing an expired peer terminates instead of recursing', function () {
    addPeer($this->peers, 1, '-qB4210-cccccccccccc', isSeeder: true);
    backdatePeer(1, '-qB4210-cccccccccccc');

    $removed = $this->peers->removePeer(1, '-qB4210-cccccccccccc');

    expect($removed)->not->toBeNull()
        ->and($this->peers->getSeeders(1))->toBe(0);
});

test('cleanupExpiredPeers sweeps expired peers and settles the counters', function () {
    addPeer($this->peers, 1, '-qB4210-dddddddddddd', isSeeder: true);
    addPeer($this->peers, 1, '-qB4210-eeeeeeeeeeee', isSeeder: false);
    backdatePeer(1, '-qB4210-dddddddddddd');
    backdatePeer(1, '-qB4210-eeeeeeeeeeee');

    expect($this->peers->cleanupExpiredPeers(1))->toBe(2)
        ->and($this->peers->getSeeders(1))->toBe(0)
        ->and($this->peers->getLeechers(1))->toBe(0);
});

test('cleanupExpiredPeers leaves live peers alone', function () {
    addPeer($this->peers, 1, '-qB4210-ffffffffffff', isSeeder: true);

    expect($this->peers->cleanupExpiredPeers(1))->toBe(0)
        ->and($this->peers->getSeeders(1))->toBe(1);
});
