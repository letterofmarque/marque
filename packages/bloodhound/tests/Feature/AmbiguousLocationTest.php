<?php

declare(strict_types=1);

// Job #319 / #11133: a location refusal that may be one dual-stack client
// announcing over IPv4 and IPv6 in turn is ambiguous, and
// anti_cheat.ambiguous_locations decides: refuse, flag (the default) or
// allow. The attack sequences from the 2026-11-01 reviews must never be
// accepted silently. All through real announces.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Events\CheatDetected;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\AntiCheatService;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => true]);
    Event::fake([CheatDetected::class]);

    $this->user = TestUser::create(['name' => 'Amb', 'email' => 'amb@example.com', 'password' => 'p']);
    issueAnnounceKey($this->user, 'ambambambambambambambambambamb00');
    $this->torrent = Torrent::create([
        'name' => 'Amb', 'info_hash' => str_repeat('a', 40), 'size' => 1_000_000_000, 'user_id' => $this->user->id,
    ]);

    $this->t = Carbon::parse('2026-11-02 10:00:00');
});

/**
 * A leeching announce from $peer at $ip, $seconds after the start.
 *
 * @return array<string, mixed>
 */
function ambiguous($test, string $peer, string $ip, int $seconds, array $params = []): array
{
    $test->travelTo($test->t->copy()->addSeconds($seconds));

    return Bencode::decode($test->withoutMiddleware(BlockBrowsers::class)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce/'.keyOf($test->user).'?'.http_build_query(array_merge([
            'info_hash' => hex2bin($test->torrent->info_hash), 'peer_id' => $peer, 'port' => 51413,
            'uploaded' => 0, 'downloaded' => 0, 'left' => 500, 'compact' => 1, 'event' => 'started',
        ], $params)))
        ->assertOk()
        ->getContent());
}

/**
 * A house at 203.0.113.7 / 2001:db8:1:2::/64: the laptop is IPv4-only, the
 * desktop dual-stack and announces over IPv4 first. Returns the desktop's
 * IPv6 announce.
 *
 * @return array<string, mixed>
 */
function household($test, int $at = 0, int $gap = 5): array
{
    ambiguous($test, '-qB4210-laptop000000', '203.0.113.7', $at);
    ambiguous($test, '-qB4210-desktop00000', '203.0.113.7', $at + 10);

    return ambiguous($test, '-qB4210-desktop00000', '2001:db8:1:2::10', $at + 10 + $gap, ['event' => '']);
}

function lastRowOf(string $peer): AnnounceLog
{
    return AnnounceLog::query()->where('peer_id', $peer)->orderByDesc('id')->firstOrFail();
}

describe('flag (the default)', function () {
    test('a dual-stack household is never refused; the ambiguous announce is credited and its row flagged', function () {
        expect(config('bloodhound.anti_cheat.ambiguous_locations'))->toBe('flag');

        $response = household($this);
        $row = lastRowOf('-qB4210-desktop00000');

        expect($response)->not->toHaveKey('failure reason')
            ->and($row->anti_cheat_flagged)->toBeTrue()
            ->and($row->anti_cheat_reason)->toStartWith(AntiCheatService::AMBIGUOUS_LOCATION)
            ->and($row->prior_up)->not->toBeNull();

        Event::assertDispatched(CheatDetected::class, fn (CheatDetected $e) => $e->type === 'location_ambiguous' && $e->userId === $this->user->id);
    });

    test('it keeps flagging every ambiguous row, and fires the event once a day per peer', function () {
        household($this);

        foreach ([1_800, 3_600, 5_400] as $later) {
            ambiguous($this, '-qB4210-laptop000000', '203.0.113.7', $later, ['event' => '']);
            ambiguous($this, '-qB4210-desktop00000', '203.0.113.7', $later + 1, ['event' => '']);
            expect(ambiguous($this, '-qB4210-desktop00000', '2001:db8:1:2::10', $later + 6, ['event' => '']))->not->toHaveKey('failure reason');
        }

        expect(AnnounceLog::query()->where('peer_id', '-qB4210-desktop00000')->where('anti_cheat_flagged', true)->count())->toBe(4);
        Event::assertDispatchedTimes(CheatDetected::class, 1);
    });

    test('credit on an ambiguous row lands in the member\'s totals', function () {
        household($this);
        ambiguous($this, '-qB4210-desktop00000', '203.0.113.7', 1_800, ['event' => '', 'downloaded' => 100]);
        ambiguous($this, '-qB4210-desktop00000', '2001:db8:1:2::10', 1_805, ['event' => '', 'downloaded' => 300]);

        expect(lastRowOf('-qB4210-desktop00000'))
            ->anti_cheat_flagged->toBeTrue()
            ->download_delta->toBe(200);
    });

    test('a switch across families outside the window is a plain new location, refused', function () {
        config(['bloodhound.anti_cheat.ambiguous_window' => 300]);

        expect(household($this, gap: 400))->toHaveKey('failure reason');
    });

    test('the window spans an announce interval by default, so a dual-stack client\'s first family each cycle is not refused', function () {
        expect(household($this, gap: 1_790))->not->toHaveKey('failure reason');
    });
});

// Documented, not fixed: making it ambiguous would let a new client match the
// other-family address an attacker's peer ID left behind (Sequence B).
test('a new client whose first announce comes over the other family is refused once, then ambiguous', function () {
    ambiguous($this, '-qB4210-laptop000000', '203.0.113.7', 0);

    expect(ambiguous($this, '-qB4210-desktop00000', '2001:db8:1:2::10', 10))->toHaveKey('failure reason')
        ->and(ambiguous($this, '-qB4210-desktop00000', '203.0.113.7', 11))->not->toHaveKey('failure reason')
        ->and(ambiguous($this, '-qB4210-desktop00000', '2001:db8:1:2::10', 70, ['event' => '']))->not->toHaveKey('failure reason');
});

test('an IPv4-only client starting while the dual-stack one has only used IPv6 is refused until that one announces over IPv4', function () {
    ambiguous($this, '-qB4210-desktop00000', '2001:db8:1:2::10', 0);

    expect(ambiguous($this, '-qB4210-laptop000000', '203.0.113.7', 5))->toHaveKey('failure reason');

    ambiguous($this, '-qB4210-desktop00000', '203.0.113.7', 10, ['event' => '']);

    expect(ambiguous($this, '-qB4210-laptop000000', '203.0.113.7', 15))->not->toHaveKey('failure reason');
});

describe('refuse', function () {
    test('the household\'s IPv6 announces are refused, as in 10.2, and no event fires', function () {
        config(['bloodhound.anti_cheat.ambiguous_locations' => 'refuse']);

        expect(household($this)['failure reason'])->toContain('Location limit reached');
        Event::assertNotDispatched(CheatDetected::class);
    });
});

describe('allow', function () {
    test('the household\'s IPv6 announces are accepted with no flag and no event', function () {
        config(['bloodhound.anti_cheat.ambiguous_locations' => 'allow']);

        expect(household($this))->not->toHaveKey('failure reason')
            ->and(lastRowOf('-qB4210-desktop00000')->anti_cheat_flagged)->toBeFalse();
        Event::assertNotDispatched(CheatDetected::class);
    });
});

test('a value it doesn\'t know means flag', function () {
    config(['bloodhound.anti_cheat.ambiguous_locations' => 'flagg']);

    expect(household($this))->not->toHaveKey('failure reason')
        ->and(lastRowOf('-qB4210-desktop00000')->anti_cheat_reason)->toStartWith(AntiCheatService::AMBIGUOUS_LOCATION);
});

// The 2026-11-01 review sequences. Under refuse each is refused; under flag
// each is refused or leaves a flagged row; under allow, the one that is
// ambiguous by design passes, which is what allow is for.
describe('the 2026-11-01 attack sequences', function () {
    // One peer ID, home over IPv4 then a friend's over IPv6; stock clients at both.
    test('B: one peer ID linking two places, then ordinary clients at each, is refused', function (string $policy) {
        config(['bloodhound.anti_cheat.ambiguous_locations' => $policy]);

        ambiguous($this, '-qB4210-xxxxxxxxxxxx', '203.0.113.7', 0);
        ambiguous($this, '-qB4210-xxxxxxxxxxxx', '2001:db8:9::1', 30, ['event' => '']);

        expect(ambiguous($this, '-qB4210-yyyyyyyyyyyy', '203.0.113.7', 60))->toHaveKey('failure reason');
    })->with(['refuse', 'flag', 'allow']);

    // A helper peer at home moves to a friend's over the other family.
    test('a peer leaving home for a friend\'s over the other family is refused or flagged, never silent', function (string $policy) {
        config(['bloodhound.anti_cheat.ambiguous_locations' => $policy]);

        ambiguous($this, '-qB4210-xxxxxxxxxxxx', '203.0.113.7', 0);
        ambiguous($this, '-qB4210-yyyyyyyyyyyy', '203.0.113.7', 10);
        $moved = ambiguous($this, '-qB4210-xxxxxxxxxxxx', '2001:db8:9::1', 20, ['event' => '']);

        if ($policy === 'refuse') {
            expect($moved)->toHaveKey('failure reason');
        } else {
            expect($moved)->not->toHaveKey('failure reason')
                ->and(lastRowOf('-qB4210-xxxxxxxxxxxx')->anti_cheat_reason)->toStartWith(AntiCheatService::AMBIGUOUS_LOCATION);
        }
    })->with(['refuse', 'flag']);

    test('claiming the owner\'s address with ipv4= never places a client there', function (string $policy) {
        config(['bloodhound.anti_cheat.ambiguous_locations' => $policy]);

        ambiguous($this, '-qB4210-xxxxxxxxxxxx', '203.0.113.7', 0);

        foreach ([10, 400, 800] as $at) {
            expect(ambiguous($this, '-qB4210-yyyyyyyyyyyy', '2001:db8:9::1', $at, ['ipv4' => '203.0.113.7']))->toHaveKey('failure reason');
        }
    })->with(['refuse', 'flag', 'allow']);

    test('a laptop leaving a dual-stack home for an IPv4-only network is refused', function (string $policy) {
        config(['bloodhound.anti_cheat.ambiguous_locations' => $policy]);

        household($this);

        expect(ambiguous($this, '-qB4210-desktop00000', '198.51.100.9', 60, ['event' => '']))->toHaveKey('failure reason');
    })->with(['refuse', 'flag', 'allow']);
});
