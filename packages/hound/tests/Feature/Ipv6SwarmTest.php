<?php

declare(strict_types=1);

// #11112: IPv6 peers were recorded and never handed out, through threepio's
// TrackerResponse. hound gets peers6 and the per-family record; it ignores
// ipv4= and ipv6= unless the operator trusts them, because nobody answers for
// an announce on a public tracker.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    DB::table('users')->insert(['id' => 1, 'name' => 'Host', 'email' => 'host-six@example.com', 'password' => 'p']);

    $this->torrent = Torrent::create(['name' => 'six', 'info_hash' => str_repeat('6', 40), 'size' => 1_000, 'user_id' => 1]);
});

/**
 * @return array<string, mixed>
 */
function houndAnnounced6($test, string $peerId, string $ip, array $params = []): array
{
    return Bencode::decode($test->withoutMiddleware(BlockBrowsers::class)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce?'.http_build_query(array_merge([
            'info_hash' => hex2bin($test->torrent->info_hash), 'peer_id' => $peerId, 'port' => 51413,
            'uploaded' => 0, 'downloaded' => 0, 'left' => 0, 'compact' => 1, 'event' => 'started',
        ], $params)))
        ->getContent());
}

it('hands an IPv6 seeder to an IPv4 leecher in peers6, and the IPv4 leecher back in peers', function () {
    houndAnnounced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8::1');
    $leecher = houndAnnounced6($this, '-qB4210-bbbbbbbbbbbb', '203.0.113.7', ['left' => 500]);

    expect($leecher['peers'])->toBe('')
        ->and($leecher['peers6'])->toBe(inet_pton('2001:db8::1').pack('n', 51413));

    $seeder = houndAnnounced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8::1', ['event' => '']);

    expect($seeder['peers'])->toBe(inet_pton('203.0.113.7').pack('n', 51413));
});

it('hands out a client announcing over each family in turn in both', function () {
    houndAnnounced6($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7');
    houndAnnounced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8::1', ['event' => '']);

    $leecher = houndAnnounced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', ['left' => 500]);

    expect(strlen($leecher['peers']))->toBe(6)
        ->and(strlen($leecher['peers6']))->toBe(18);
});

it('ignores ipv4= by default, so nobody can put an address into a swarm', function () {
    houndAnnounced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8::1', ['ipv4' => '192.0.2.1']);
    $leecher = houndAnnounced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', ['left' => 500]);

    expect($leecher['peers'])->toBe('');
});

it('honours ipv4= when the operator trusts announced addresses', function () {
    config(['hound.trust_announced_addresses' => true]);

    houndAnnounced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8::1', ['ipv4' => '192.0.2.1']);
    $leecher = houndAnnounced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', ['left' => 500]);

    expect($leecher['peers'])->toBe(inet_pton('192.0.2.1').pack('n', 51413));
});
