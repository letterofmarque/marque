<?php

declare(strict_types=1);

// #11057: info_hash and peer_id are 20 raw bytes. Laravel's TrimStrings
// stripped NUL and whitespace from their ends (about one torrent in twenty
// could never be announced), and PHP kept only the last of a scrape's
// repeated info_hash keys.

use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => false]);
    $this->user = makeTrackerUser('binarybinarybinarybinarybinary00', 'binary@example.com');
});

function edgeTorrent(int $i, string $hex): Torrent
{
    return Torrent::create(['name' => "edge{$i}", 'info_hash' => $hex, 'size' => 1_000, 'user_id' => test()->user->id]);
}

it('announces torrents whose info_hash starts or ends with a byte trimming strips', function () {
    foreach (['00', '20', '09', '0a', '0d', '0b'] as $i => $edge) {
        $hex = $edge.str_repeat('ab', 18).$edge;
        edgeTorrent($i, $hex);

        $body = $this->withoutMiddleware(BlockBrowsers::class)->get('/announce/'.keyOf($this->user).'?'.http_build_query([
            'info_hash' => hex2bin($hex), 'peer_id' => '-qB4210-aaaaaaaaaaaa', 'port' => 51413,
            'uploaded' => 0, 'downloaded' => 0, 'left' => 0, 'event' => 'started', 'compact' => 1,
        ]))->getContent();

        expect($body)->not->toContain('failure reason');
    }

    expect(AnnounceLog::query()->count())->toBe(6);
});

it('keeps a peer_id whose last byte trimming strips', function () {
    $torrent = edgeTorrent(9, str_repeat('cd', 20));
    $peerId = '-qB4210-aaaaaaaaaaa ';

    $this->withoutMiddleware(BlockBrowsers::class)->get('/announce/'.keyOf($this->user).'?'.http_build_query([
        'info_hash' => hex2bin($torrent->info_hash), 'peer_id' => $peerId, 'port' => 51413,
        'uploaded' => 0, 'downloaded' => 0, 'left' => 0, 'event' => 'started', 'compact' => 1,
    ]))->assertOk()->assertDontSee('failure reason');

    expect(AnnounceLog::query()->value('peer_id'))->toBe($peerId);
});

it('scrapes every info_hash a client asks for, edge bytes included', function () {
    $a = edgeTorrent(1, '00'.str_repeat('ab', 19));
    $b = edgeTorrent(2, str_repeat('ab', 19).'20');

    $body = $this->withoutMiddleware(BlockBrowsers::class)
        ->get('/scrape/'.keyOf($this->user).'?info_hash='.rawurlencode(hex2bin($a->info_hash)).'&info_hash='.rawurlencode(hex2bin($b->info_hash)))
        ->getContent();

    expect(array_keys(Bencode::decode($body)['files']))->toHaveCount(2);
});

// #11058: a peer ID that is not valid UTF-8 (BEP 3 allows any 20 bytes)
// failed the ledger insert on MySQL, MariaDB and PostgreSQL, and PostgreSQL
// truncated one at a NUL.
it('credits a binary peer ID, and finds it again after Redis is lost', function () {
    $torrent = edgeTorrent(20, str_repeat('ef', 20));
    $peerId = "-lt0D80-\x9a\xb3\xff\x00\x01abcdefg";

    $announce = fn (int $uploaded, string $event = '') => $this->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => "rtorrent/\xff"])
        ->get('/announce/'.keyOf($this->user).'?'.http_build_query(array_filter([
            'info_hash' => hex2bin($torrent->info_hash), 'peer_id' => $peerId, 'port' => 51413,
            'uploaded' => $uploaded, 'downloaded' => 0, 'left' => 0, 'event' => $event, 'compact' => 1,
        ], fn ($v) => $v !== '')))
        ->assertOk()->assertDontSee('failure reason');

    $announce(1_000, 'started');
    $announce(3_000);

    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    $announce(4_500);

    expect(AnnounceLog::query()->orderBy('id')->pluck('upload_delta')->all())->toBe([0, 2_000, 1_500])
        ->and(AnnounceLog::query()->distinct()->count('peer_id'))->toBe(1);
});
