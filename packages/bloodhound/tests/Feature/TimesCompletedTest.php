<?php

declare(strict_types=1);

// Does torrents.times_completed agree with per-user completions? Since CP6,
// yes — this file is the record of how it did not, and now does.
//
// torrent_user has a UNIQUE(user_id, torrent_id) and dedupes completions per
// user, so "one row per user per torrent" is deliberate and enforced. The
// torrents.times_completed counter is incremented on the same event with no
// such guard, and `event` is a client-supplied URL parameter.
//
// (Was written against `snatches`, which torrent_user absorbed in CP4 — the
// divergence is unchanged, only the table it is measured against.)

use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Trove\Models\Torrent;
use Orchestra\Testbench\TestCase;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'completed@example.com',
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

function completedUrl(TestUser $user, Torrent $torrent, string $peerId = '-qB4210-aaaaaaaaaaaa'): string
{
    $query = http_build_query([
        'info_hash' => hex2bin($torrent->info_hash),
        'peer_id' => $peerId,
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => $torrent->size,
        'left' => 0,
        'compact' => 1,
        'event' => 'completed',
    ]);

    return "/announce/{$user->announce_key}?{$query}";
}

function hitAnnounce(TestCase $test, string $url): TestResponse
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get($url);
}

test('a single completion records one torrent_user row and one completion', function () {
    hitAnnounce($this, completedUrl($this->user, $this->torrent))->assertOk();

    expect(TorrentUser::count())->toBe(1)
        ->and($this->torrent->fresh()->times_completed)->toBe(1);
});

// Anti-cheat's frequency check is keyed on (torrent, peer_id), so repeat
// completions from ONE peer_id are rejected and never reach the increment.
// A different peer_id is not an exotic attack — it is the same user on a
// second machine, or the same client after a restart, since peer_id is
// regenerated per session.
test('the same user completing from several peer ids records one torrent_user row', function () {
    foreach (['aaaa', 'bbbb', 'cccc', 'dddd', 'eeee'] as $suffix) {
        hitAnnounce($this, completedUrl($this->user, $this->torrent, "-qB4210-{$suffix}12345678"))
            ->assertOk();
    }

    expect(TorrentUser::count())->toBe(1);
});

// FIXED by CP6. This was pinned as a known bug in 91f78dc, asserting the wrong
// behaviour to keep the suite green while it stood on record; it is now an
// ordinary regression test.
//
// Five completions from one user used to give times_completed = 3 against one
// torrent_user row — three rather than five because anti-cheat's frequency
// check (keyed on torrent+peer_id) caught two, which made the inflation
// arbitrary as well as wrong. Completions are now deduped per user per
// download session, so both numbers agree.
test('times_completed agrees with the per-user completion count across peer ids', function () {
    foreach (['aaaa', 'bbbb', 'cccc', 'dddd', 'eeee'] as $suffix) {
        hitAnnounce($this, completedUrl($this->user, $this->torrent, "-qB4210-{$suffix}12345678"))
            ->assertOk();
    }

    expect(TorrentUser::count())->toBe(1)
        ->and($this->torrent->fresh()->times_completed)->toBe(1);
});
