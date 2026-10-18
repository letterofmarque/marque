<?php

declare(strict_types=1);

// bloodhound 8.0.0 (Job #193, design section 1): each announce is credited once,
// at announce time, against the peer's record in Redis. Bytes and seconds go
// on the ledger row together, with the baseline they were measured from; the
// row is written before Redis, so a failed insert leaves the baseline intact.

use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Models\Torrent;
use Orchestra\Testbench\TestCase;

beforeEach(function () {
    $this->redis = Redis::connection(config('threepio.redis.connection', 'default'));
    $this->redis->flushdb();
    $this->prefix = config('threepio.redis.prefix', 'marque:');

    config(['bloodhound.anti_cheat.enabled' => false]);

    // Intervals are asserted to the second: a second ticking between two
    // announces would read as one more (found by the third check).
    $this->freezeTime();

    $this->user = TestUser::create(['name' => 'Credit', 'email' => 'credit@example.com', 'password' => 'password']);
    issueAnnounceKey($this->user, 'creditcreditcreditcreditcredit00');

    $this->torrent = Torrent::create([
        'name' => 'Credit Torrent',
        'info_hash' => str_repeat('c', 40),
        'size' => 1_000_000,
        'user_id' => $this->user->id,
    ]);
});

function creditAnnounce(TestCase $test, TestUser $user, Torrent $torrent, array $params = []): TestResponse
{
    $query = http_build_query(array_merge([
        'info_hash' => hex2bin($torrent->info_hash),
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'compact' => 1,
    ], $params));

    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce/'.keyOf($user)."?{$query}");
}

/** Move the peer's last announce into the past, as if $seconds had gone by. */
function ageCreditPeer(int $torrentId, int $seconds, string $peerId = '-qB4210-aaaaaaaaaaaa'): int
{
    $redis = Redis::connection(config('threepio.redis.connection', 'default'));
    $key = config('threepio.redis.prefix', 'marque:')."peers:{$torrentId}";

    $peer = json_decode($redis->hget($key, $peerId), true);
    $peer['last_action'] -= $seconds;
    $redis->hset($key, $peerId, json_encode($peer));

    return $peer['last_action'];
}

describe('time is credited on the row, at announce time', function () {
    test('a seeder\'s regular announce credits the interval as seedtime', function () {
        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'started', 'uploaded' => 1_000])->assertOk();
        $priorAt = ageCreditPeer($this->torrent->id, 1_800);

        creditAnnounce($this, $this->user, $this->torrent, ['uploaded' => 4_000])->assertOk();

        $row = AnnounceLog::orderByDesc('id')->first();

        expect($row->upload_delta)->toBe(3_000)
            ->and($row->seed_seconds)->toBe(1_800)
            ->and($row->leech_seconds)->toBe(0)
            ->and($row->prior_up)->toBe(1_000)
            ->and($row->prior_at?->getTimestamp())->toBe($priorAt);
    });

    // Spec #121: an interval belongs to the state it started in, so the
    // announce that reports completion closes a leeching interval.
    test('the interval that ends in completion is leechtime', function () {
        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'started', 'left' => 500_000])->assertOk();
        ageCreditPeer($this->torrent->id, 900);

        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'completed', 'left' => 0, 'downloaded' => 500_000])->assertOk();

        $row = AnnounceLog::orderByDesc('id')->first();

        expect($row->event)->toBe('completed')
            ->and($row->leech_seconds)->toBe(900)
            ->and($row->seed_seconds)->toBe(0)
            ->and($row->download_delta)->toBe(500_000);
    });

    test('a gap longer than peer_expiry is credited at the cap', function () {
        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'started'])->assertOk();
        // Expired but not yet swept: still the member's baseline, and the gap
        // is credited at peer_expiry (3600 here), not the full 50,000 seconds.
        ageCreditPeer($this->torrent->id, 50_000);
        creditAnnounce($this, $this->user, $this->torrent)->assertOk();

        expect(AnnounceLog::orderBy('id')->pluck('seed_seconds')->all())->toBe([0, 3_600]);
    });

    test('a first announce credits no time and has no baseline', function () {
        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'started', 'uploaded' => 7_000])->assertOk();

        $row = AnnounceLog::first();

        expect($row->seed_seconds)->toBe(0)
            ->and($row->leech_seconds)->toBe(0)
            ->and($row->upload_delta)->toBe(0)
            ->and($row->prior_up)->toBeNull()
            ->and($row->prior_at)->toBeNull();
    });
});

describe('stopped (F3)', function () {
    test('credits the bytes and time since the last announce, then removes the peer', function () {
        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'started'])->assertOk();
        creditAnnounce($this, $this->user, $this->torrent, ['uploaded' => 1_000])->assertOk();
        ageCreditPeer($this->torrent->id, 600);

        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'stopped', 'uploaded' => 5_000])->assertOk();

        $row = AnnounceLog::orderByDesc('id')->first();

        expect($row->event)->toBe('stopped')
            ->and($row->upload_delta)->toBe(4_000)
            ->and($row->prior_up)->toBe(1_000)
            ->and($row->seed_seconds)->toBe(600)
            ->and(app(PeerService::class)->find($this->torrent->id, '-qB4210-aaaaaaaaaaaa'))->toBeNull();
    });

    test('a stop with no baseline credits nothing', function () {
        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'stopped', 'uploaded' => 5_000])->assertOk();

        $row = AnnounceLog::first();

        expect($row->upload_delta)->toBe(0)
            ->and($row->seed_seconds)->toBe(0)
            ->and($row->prior_up)->toBeNull();
    });
});

describe('rejections credit nothing and touch nothing (F1)', function () {
    test('an anti-cheat rejection is logged with no credit, no baseline, and the peer record unchanged', function () {
        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'started', 'uploaded' => 1_000])->assertOk();
        $before = app(PeerService::class)->find($this->torrent->id, '-qB4210-aaaaaaaaaaaa');

        config(['bloodhound.anti_cheat.enabled' => true]);
        creditAnnounce($this, $this->user, $this->torrent, ['uploaded' => 2_000, 'port' => 6881])
            ->assertOk()
            ->assertSee('blacklisted');

        $row = AnnounceLog::orderByDesc('id')->first();

        expect($row->anti_cheat_flagged)->toBeTrue()
            ->and($row->upload_delta)->toBe(0)
            ->and($row->seed_seconds)->toBe(0)
            ->and($row->leech_seconds)->toBe(0)
            ->and($row->prior_up)->toBeNull()
            ->and($row->prior_at)->toBeNull()
            ->and(app(PeerService::class)->find($this->torrent->id, '-qB4210-aaaaaaaaaaaa'))->toBe($before);
    });

    // F21: a refused client is still an announce from a known member on a known
    // torrent, and "every announce is logged" should be true.
    test('a client-validation refusal is logged as a flagged row', function () {
        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'started', 'peer_id' => '-XX0001-aaaaaaaaaaaa'])
            ->assertOk()
            ->assertSee('failure reason');

        $row = AnnounceLog::first();

        expect($row)->not->toBeNull()
            ->and($row->anti_cheat_flagged)->toBeTrue()
            ->and($row->anti_cheat_reason)->not->toBeNull()
            ->and($row->upload_delta)->toBe(0)
            ->and($row->seed_seconds)->toBe(0)
            ->and(app(PeerService::class)->find($this->torrent->id, '-XX0001-aaaaaaaaaaaa'))->toBeNull();
    });
});

describe('the ledger row is written before Redis', function () {
    // If the insert fails, the client retries against an untouched baseline and
    // nothing is lost. Redis-first lost the interval: the baseline had moved on.
    test('a failed insert leaves the peer\'s baseline where it was', function () {
        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'started', 'uploaded' => 1_000])->assertOk();
        $before = app(PeerService::class)->find($this->torrent->id, '-qB4210-aaaaaaaaaaaa');

        AnnounceLog::creating(function () {
            throw new RuntimeException('ledger unavailable');
        });

        $this->withoutExceptionHandling();

        expect(fn () => creditAnnounce($this, $this->user, $this->torrent, ['uploaded' => 3_000]))
            ->toThrow(RuntimeException::class, 'ledger unavailable');

        expect(app(PeerService::class)->find($this->torrent->id, '-qB4210-aaaaaaaaaaaa'))->toBe($before);
    });

    test('a failed insert on stopped leaves the peer in the swarm', function () {
        creditAnnounce($this, $this->user, $this->torrent, ['event' => 'started', 'uploaded' => 1_000])->assertOk();

        AnnounceLog::creating(function () {
            throw new RuntimeException('ledger unavailable');
        });

        $this->withoutExceptionHandling();

        expect(fn () => creditAnnounce($this, $this->user, $this->torrent, ['event' => 'stopped', 'uploaded' => 3_000]))
            ->toThrow(RuntimeException::class);

        expect(app(PeerService::class)->find($this->torrent->id, '-qB4210-aaaaaaaaaaaa'))->not->toBeNull();
    });
});
