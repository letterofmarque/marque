<?php

declare(strict_types=1);

// Job #193 (design 2.2–2.5, addenda A3 and A4): anti-cheat judges an announce
// against the same peer record the credit uses, and no longer refuses
// legitimate clients. Each block below was a reproduction in the audit
// (F6–F9, F14), through real announces.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Contracts\SeedboxRegistry;
use Marque\Bloodhound\Events\CheatDetected;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => true]);

    $this->user = TestUser::create(['name' => 'Rules', 'email' => 'rules@example.com', 'password' => 'p']);
    issueAnnounceKey($this->user, 'rulesrulesrulesrulesrulesrules00');
    $this->torrent = Torrent::create([
        'name' => 'Rules', 'info_hash' => str_repeat('f', 40), 'size' => 1_000_000_000, 'user_id' => $this->user->id,
    ]);

    $this->t = Carbon::parse('2026-10-18 10:00:00');
    $this->travelTo($this->t);
});

/**
 * One announce from $peer at $ip, $seconds after the start. Returns the body.
 */
function ruled($test, string $peer, string $ip, int $seconds, array $params = [], ?TestUser $user = null): string
{
    $test->travelTo($test->t->copy()->addSeconds($seconds));
    $user ??= $test->user;

    $query = http_build_query(array_merge([
        'info_hash' => hex2bin($test->torrent->info_hash),
        'peer_id' => $peer,
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'compact' => 1,
    ], $params));

    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce/'.keyOf($user)."?{$query}")
        ->assertOk()
        ->getContent();
}

function refused(string $body): bool
{
    return str_contains($body, 'failure reason');
}

describe('location limits (F6, A3)', function () {
    // The audit's B2: three clients joined, then every regular announce from
    // all three was refused, because the count included the announcer.
    test('a member at the seeding limit can keep announcing from every location', function () {
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $i => $ip) {
            expect(refused(ruled($this, sprintf('-qB4210-%012d', $i), $ip, $i, ['event' => 'started'])))->toBeFalse();
        }

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $i => $ip) {
            expect(refused(ruled($this, sprintf('-qB4210-%012d', $i), $ip, 1_800 + $i)))->toBeFalse();
        }
    });

    test('a new seeding location past the limit is refused', function () {
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $i => $ip) {
            ruled($this, sprintf('-qB4210-%012d', $i), $ip, $i, ['event' => 'started']);
        }

        $body = ruled($this, '-qB4210-000000000009', '10.0.0.4', 10, ['event' => 'started']);

        expect(refused($body))->toBeTrue()
            ->and($body)->toContain('3 seeding locations');
    });

    test('a second leeching location is refused, a second client at the same one is not', function () {
        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'left' => 500]);

        expect(refused(ruled($this, '-qB4210-bbbbbbbbbbbb', '10.0.0.1', 5, ['event' => 'started', 'left' => 500])))->toBeFalse()
            ->and(refused(ruled($this, '-qB4210-cccccccccccc', '10.0.0.2', 10, ['event' => 'started', 'left' => 500])))->toBeTrue();
    });

    // Seeding from home does not use up the one leeching location, and
    // leeching from a seedbox does not use up a seeding one.
    test('seeding and leeching locations are counted separately', function () {
        config(['bloodhound.anti_cheat.max_seeding_locations_per_torrent' => 1]);

        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'left' => 0]);

        expect(refused(ruled($this, '-qB4210-bbbbbbbbbbbb', '10.0.0.2', 5, ['event' => 'started', 'left' => 500])))->toBeFalse()
            ->and(refused(ruled($this, '-qB4210-cccccccccccc', '10.0.0.3', 10, ['event' => 'started', 'left' => 0])))->toBeTrue();
    });

    test('one IPv6 /64 is one location', function () {
        config(['bloodhound.anti_cheat.max_seeding_locations_per_torrent' => 1]);

        ruled($this, '-qB4210-aaaaaaaaaaaa', '2001:db8:1:2::10', 0, ['event' => 'started']);

        expect(refused(ruled($this, '-qB4210-bbbbbbbbbbbb', '2001:db8:1:2::99', 5, ['event' => 'started'])))->toBeFalse()
            ->and(refused(ruled($this, '-qB4210-cccccccccccc', '2001:db8:1:3::10', 10, ['event' => 'started'])))->toBeTrue();
    });

    test('a peer already in the swarm is never refused by a count, even after the limit is lowered', function () {
        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started']);
        ruled($this, '-qB4210-bbbbbbbbbbbb', '10.0.0.2', 1, ['event' => 'started']);
        config(['bloodhound.anti_cheat.max_seeding_locations_per_torrent' => 1]);

        expect(refused(ruled($this, '-qB4210-bbbbbbbbbbbb', '10.0.0.2', 1_800)))->toBeFalse();
    });

    // The audit's B3: an IP at its session cap refused its own next announce.
    test('an IP at its session cap can still announce its existing sessions', function () {
        config(['bloodhound.anti_cheat.max_connections_per_ip' => 2]);
        $other = Torrent::create(['name' => 'Two', 'info_hash' => str_repeat('e', 40), 'size' => 1_000, 'user_id' => $this->user->id]);

        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started']);
        ruled($this, '-qB4210-bbbbbbbbbbbb', '10.0.0.1', 1, ['event' => 'started']);

        $newSession = $this->withoutMiddleware(BlockBrowsers::class)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->get('/announce/'.keyOf($this->user).'?'.http_build_query([
                'info_hash' => hex2bin($other->info_hash), 'peer_id' => '-qB4210-cccccccccccc', 'port' => 51413,
                'uploaded' => 0, 'downloaded' => 0, 'left' => 0, 'event' => 'started', 'compact' => 1,
            ]))->getContent();

        expect(refused($newSession))->toBeTrue()
            ->and(refused(ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 1_800)))->toBeFalse();
    });
});

describe('the minimum gap (F7)', function () {
    // The audit's B1: a torrent finished inside a minute never recorded its
    // completion.
    test('completed is never refused for coming soon after started', function () {
        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'left' => 500]);

        expect(refused(ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 20, ['event' => 'completed', 'left' => 0, 'downloaded' => 500])))->toBeFalse()
            ->and($this->torrent->fresh()->times_completed)->toBe(1);
    });

    // The audit's A4: a refused stop left a ghost seeder in the swarm.
    test('stopped is never refused, and the peer leaves', function () {
        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started']);

        expect(refused(ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 10, ['event' => 'stopped'])))->toBeFalse()
            ->and(app(PeerService::class)->find($this->torrent->id, '-qB4210-aaaaaaaaaaaa'))->toBeNull();
    });

    test('a regular announce inside the gap is refused, and the refusal does not restart the clock', function () {
        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started']);

        expect(refused(ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 30)))->toBeTrue()
            ->and(refused(ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 61)))->toBeFalse();
    });

    test('nothing is written to Redis for the gap any more', function () {
        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started']);

        $keys = Redis::connection(config('threepio.redis.connection', 'default'))->keys('*announce_time*');

        expect($keys)->toBe([]);
    });
});

describe('data sanity (F8)', function () {
    // The audit's B4: resuming a half-finished torrent reports left = half and
    // downloaded = 0 (counters are per session), and was refused for good.
    test('resuming a partial download is accepted', function () {
        expect(refused(ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'left' => 500_000_000, 'downloaded' => 0])))->toBeFalse();
    });

    test('more left than the torrent holds is reported, not refused', function () {
        Event::fake([CheatDetected::class]);

        expect(refused(ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'left' => 2_000_000_000])))->toBeFalse();

        Event::assertDispatched(CheatDetected::class, fn ($event) => $event->type === 'data_anomaly');
    });
});

describe('speed (F9, F14, A4)', function () {
    beforeEach(function () {
        config([
            'bloodhound.anti_cheat.max_upload_speed' => 1_000,
            'bloodhound.anti_cheat.max_download_speed' => 1_000,
            'bloodhound.anti_cheat.min_announce_gap' => 0,
        ]);
    });

    // The audit's B5: a rejection left the baseline where it was, so the next
    // announce credited the same bytes over a longer window.
    test('bytes over the cap are forfeited, the baseline moves on, and they never land', function () {
        Event::fake([CheatDetected::class]);
        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started']);

        // 10 seconds: 1,000 B/s allows 10,000; it claims 50,000 up and 5,000 down.
        expect(refused(ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 10, ['uploaded' => 50_000, 'downloaded' => 5_000])))->toBeFalse();

        $row = AnnounceLog::query()->orderByDesc('id')->first();

        expect($row->upload_delta)->toBe(0)
            ->and($row->download_delta)->toBe(5_000)
            ->and($row->seed_seconds)->toBe(10)
            ->and($row->anti_cheat_flagged)->toBeTrue()
            ->and($row->anti_cheat_reason)->toContain('Upload over the cap');

        Event::assertDispatched(CheatDetected::class, fn ($event) => $event->type === 'speed_violation');

        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 30, ['uploaded' => 55_000, 'downloaded' => 5_000]);

        expect(AnnounceLog::query()->orderByDesc('id')->value('upload_delta'))->toBe(5_000);
    });

    test('an approved seedbox of the member is held to the seedbox cap', function () {
        config(['bloodhound.anti_cheat.seedbox.max_upload_speed' => 100_000]);
        app()->instance(SeedboxRegistry::class, new class implements SeedboxRegistry
        {
            public function isSeedbox(int $userId, string $ip): bool
            {
                return $ip === '10.0.0.7';
            }
        });

        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.7', 0, ['event' => 'started']);
        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.7', 10, ['uploaded' => 50_000]);

        $row = AnnounceLog::query()->orderByDesc('id')->first();

        expect($row->upload_delta)->toBe(50_000)
            ->and($row->anti_cheat_flagged)->toBeFalse();
    });

    // F14: the speed check skipped every peer Redis had lost, while the credit
    // used the ledger's baseline. Now both read the same one.
    test('a baseline recovered from the ledger is speed-checked too', function () {
        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started']);
        Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();

        ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 10, ['uploaded' => 50_000]);

        $row = AnnounceLog::query()->orderByDesc('id')->first();

        expect($row->prior_up)->toBe(0)
            ->and($row->upload_delta)->toBe(0)
            ->and($row->anti_cheat_flagged)->toBeTrue();
    });
});

test('a port refusal is a logged row, with no event', function () {
    Event::fake([CheatDetected::class]);

    expect(refused(ruled($this, '-qB4210-aaaaaaaaaaaa', '10.0.0.1', 0, ['event' => 'started', 'port' => 6881])))->toBeTrue()
        ->and(AnnounceLog::query()->where('anti_cheat_flagged', true)->count())->toBe(1);

    Event::assertNotDispatched(CheatDetected::class);
});
