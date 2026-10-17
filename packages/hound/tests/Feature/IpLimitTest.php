<?php

declare(strict_types=1);

// Job #193 (bloodhound 8.0.0 design 2.2): the per-IP cap counts sessions (one
// peer ID on one torrent) and refuses only a NEW one. Counting the announcing
// peer itself refused an IP at its cap on its own next announce, so it could
// neither keep seeding nor say it was leaving.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    DB::table('users')->insert(['id' => 1, 'name' => 'Host', 'email' => 'host-ip@example.com', 'password' => 'p']);
    config(['hound.ip_limiting.max_per_ip' => 2]);

    $this->torrents = collect(['a', 'b', 'c'])->map(fn ($c) => Torrent::create([
        'name' => $c, 'info_hash' => str_repeat($c, 40), 'size' => 1_000, 'user_id' => 1,
    ]));
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

it('refuses a new session past the cap', function () {
    houndAnnounce($this, $this->torrents[0], '-qB4210-aaaaaaaaaaaa', ['event' => 'started']);
    houndAnnounce($this, $this->torrents[1], '-qB4210-aaaaaaaaaaaa', ['event' => 'started']);

    expect(houndAnnounce($this, $this->torrents[2], '-qB4210-aaaaaaaaaaaa', ['event' => 'started']))->toContain('Too many connections');
});

it('lets an IP at its cap keep announcing its existing sessions, and leave', function () {
    houndAnnounce($this, $this->torrents[0], '-qB4210-aaaaaaaaaaaa', ['event' => 'started']);
    houndAnnounce($this, $this->torrents[1], '-qB4210-aaaaaaaaaaaa', ['event' => 'started']);

    expect(houndAnnounce($this, $this->torrents[0], '-qB4210-aaaaaaaaaaaa'))->not->toContain('failure reason')
        ->and(houndAnnounce($this, $this->torrents[1], '-qB4210-aaaaaaaaaaaa', ['event' => 'stopped']))->not->toContain('failure reason');
});
