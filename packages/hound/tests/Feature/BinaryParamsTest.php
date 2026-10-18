<?php

declare(strict_types=1);

// #11057, on the public tracker: binary info_hash and peer_id survive
// Laravel's TrimStrings, and a scrape answers for every hash asked.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    DB::table('users')->insert(['id' => 1, 'name' => 'Host', 'email' => 'host-binary@example.com', 'password' => 'p']);
});

it('announces a torrent whose info_hash starts and ends with bytes trimming strips', function () {
    $hex = '00'.str_repeat('ab', 18).'0a';
    Torrent::create(['name' => 'e', 'info_hash' => $hex, 'size' => 1_000, 'user_id' => 1]);

    $body = $this->withoutMiddleware(BlockBrowsers::class)->get('/announce?'.http_build_query([
        'info_hash' => hex2bin($hex), 'peer_id' => '-qB4210-aaaaaaaaaaa ', 'port' => 51413,
        'uploaded' => 0, 'downloaded' => 0, 'left' => 0, 'event' => 'started', 'compact' => 1,
    ]))->getContent();

    expect($body)->not->toContain('failure reason');
});

it('scrapes every info_hash asked for', function () {
    $a = Torrent::create(['name' => 'a', 'info_hash' => '20'.str_repeat('ab', 19), 'size' => 1_000, 'user_id' => 1]);
    $b = Torrent::create(['name' => 'b', 'info_hash' => str_repeat('cd', 20), 'size' => 1_000, 'user_id' => 1]);

    $body = $this->withoutMiddleware(BlockBrowsers::class)
        ->get('/scrape?info_hash='.rawurlencode(hex2bin($a->info_hash)).'&info_hash='.rawurlencode(hex2bin($b->info_hash)))
        ->getContent();

    expect(array_keys(Bencode::decode($body)['files']))->toHaveCount(2);
});
