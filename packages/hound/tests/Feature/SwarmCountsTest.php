<?php

declare(strict_types=1);

// #10800. A `stopped` announce only read the counts — nothing in hound ever
// removed a peer — and nothing swept peers that expired without one. So every
// peer that ever announced stayed counted: seeders and leechers only climbed.

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

    DB::table('users')->insert(['id' => 1, 'name' => 'Host', 'email' => 'host@example.com', 'password' => 'password']);

    $this->torrent = Torrent::create([
        'name' => 'Swarm Torrent',
        'info_hash' => str_repeat('cd', 20),
        'size' => 1_000_000,
        'user_id' => 1,
    ]);
});

function swarmAnnounce(object $test, string $peerId, int $left, ?string $event = null): void
{
    $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce?'.http_build_query(array_filter([
            'info_hash' => str_repeat('cd', 20),
            'peer_id' => $peerId,
            'port' => 51413,
            'uploaded' => 0,
            'downloaded' => 0,
            'left' => $left,
            'event' => $event,
        ], fn ($v) => $v !== null)))
        ->assertOk();
}

it('takes a peer out of the swarm when it announces stopped', function () {
    swarmAnnounce($this, '-qB4500-aaaaaaaaaaaa', left: 0, event: 'started');
    swarmAnnounce($this, '-qB4500-bbbbbbbbbbbb', left: 500, event: 'started');

    expect($this->torrent->fresh()->seeders)->toBe(1)
        ->and($this->torrent->fresh()->leechers)->toBe(1);

    swarmAnnounce($this, '-qB4500-aaaaaaaaaaaa', left: 0, event: 'stopped');

    expect(app(PeerService::class)->getSeeders($this->torrent->id))->toBe(0)
        ->and($this->torrent->fresh()->seeders)->toBe(0)
        ->and($this->torrent->fresh()->leechers)->toBe(1);
});

it('counts a peer out after it expires without saying stopped, once the sweep runs', function () {
    swarmAnnounce($this, '-qB4500-cccccccccccc', left: 0, event: 'started');

    // Age it past expiry, as a client that was killed would be.
    $redis = Redis::connection(config('threepio.redis.connection', 'default'));
    $key = config('threepio.redis.prefix')."peers:{$this->torrent->id}";
    $peer = json_decode($redis->hget($key, '-qB4500-cccccccccccc'), true);
    $peer['last_action'] = time() - 86_400;
    $redis->hset($key, '-qB4500-cccccccccccc', json_encode($peer));

    $this->artisan('hound:sync-swarm-counts')->assertSuccessful();

    expect(app(PeerService::class)->getSeeders($this->torrent->id))->toBe(0)
        ->and($this->torrent->fresh()->seeders)->toBe(0);
});

it('schedules the sweep hourly', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'hound:sync-swarm-counts'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});

// #10804. Hound passed userId 0, which threepio treats as a real user, so every
// anonymous peer ever seen went into one `user:0:peers` set that never shrank.
it('keeps no per-user peer set, since a public tracker has no users', function () {
    swarmAnnounce($this, '-qB4500-dddddddddddd', left: 0, event: 'started');

    $redis = Redis::connection(config('threepio.redis.connection', 'default'));
    $prefix = config('threepio.redis.prefix', 'marque:');

    expect($redis->exists($prefix.'user:0:peers'))->toBe(0)
        ->and(app(PeerService::class)->getSeeders($this->torrent->id))->toBe(1);
});
