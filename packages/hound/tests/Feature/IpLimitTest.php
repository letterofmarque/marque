<?php

declare(strict_types=1);

// The per-IP cap guards one torrent's swarm against one address announcing
// many peer IDs into it. It never counts across torrents: a public seedbox
// seeding thousands of torrents from one address is the healthiest thing a
// tracker sees, and a cap over all of them refused it.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    DB::table('users')->insert(['id' => 1, 'name' => 'Host', 'email' => 'host-ip@example.com', 'password' => 'p']);
    config(['hound.ip_limiting.max_peers_per_torrent' => 2]);

    $this->torrent = Torrent::create(['name' => 'a', 'info_hash' => str_repeat('a', 40), 'size' => 1_000, 'user_id' => 1]);
});

function houndAnnounce($test, Torrent $torrent, string $peerId, array $params = []): string
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce?'.http_build_query(array_merge([
            'info_hash' => hex2bin($torrent->info_hash), 'peer_id' => $peerId, 'port' => 51413,
            'uploaded' => 0, 'downloaded' => 0, 'left' => 0, 'compact' => 1,
        ], $params)))
        ->getContent();
}

it('refuses a new peer from an address already at the cap on that torrent', function () {
    houndAnnounce($this, $this->torrent, '-qB4210-000000000001', ['event' => 'started']);
    houndAnnounce($this, $this->torrent, '-qB4210-000000000002', ['event' => 'started']);

    expect(houndAnnounce($this, $this->torrent, '-qB4210-000000000003', ['event' => 'started']))->toContain('Too many peers from your address');
});

it('lets peers already in the swarm keep announcing, and leave', function () {
    houndAnnounce($this, $this->torrent, '-qB4210-000000000001', ['event' => 'started']);
    houndAnnounce($this, $this->torrent, '-qB4210-000000000002', ['event' => 'started']);

    expect(houndAnnounce($this, $this->torrent, '-qB4210-000000000001'))->not->toContain('failure reason')
        ->and(houndAnnounce($this, $this->torrent, '-qB4210-000000000002', ['event' => 'stopped']))->not->toContain('failure reason');
});

it('never counts across torrents: one address can seed hundreds', function () {
    foreach (range(1, 150) as $i) {
        $torrent = Torrent::create(['name' => "t{$i}", 'info_hash' => str_repeat(sprintf('%02x', $i + 16), 20), 'size' => 1_000, 'user_id' => 1]);

        expect(houndAnnounce($this, $torrent, '-qB4210-aaaaaaaaaaaa', ['event' => 'started']))->not->toContain('failure reason');
    }
});
