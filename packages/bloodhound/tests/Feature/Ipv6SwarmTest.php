<?php

declare(strict_types=1);

// #11112: IPv6 peers were recorded and never handed out (compact packing used
// ip2long() and skipped what it couldn't pack), ipv4= and ipv6= were ignored,
// and a dual-stack client's single address flipped with each announce. All
// through real announces.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\RedisDurability;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => true]);

    $this->user = TestUser::create(['name' => 'Six', 'email' => 'six@example.com', 'password' => 'p']);
    issueAnnounceKey($this->user, 'sixsixsixsixsixsixsixsixsixsix00');
    $this->other = TestUser::create(['name' => 'Four', 'email' => 'four@example.com', 'password' => 'p']);
    issueAnnounceKey($this->other, 'fourfourfourfourfourfourfourfo00');
    $this->torrent = Torrent::create([
        'name' => 'Six', 'info_hash' => str_repeat('6', 40), 'size' => 1_000_000_000, 'user_id' => $this->user->id,
    ]);

    $this->t = Carbon::parse('2026-11-01 10:00:00');
});

/**
 * One announce from $peer, connecting from $ip, $seconds after the start.
 * Returns the decoded response.
 *
 * @return array<string, mixed>
 */
function announced6($test, string $peer, string $ip, int $seconds, array $params = [], ?TestUser $user = null): array
{
    $test->travelTo($test->t->copy()->addSeconds($seconds));

    $query = http_build_query(array_merge([
        'info_hash' => hex2bin($test->torrent->info_hash),
        'peer_id' => $peer,
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 0,
        'compact' => 1,
        'event' => 'started',
    ], $params));

    return Bencode::decode($test->withoutMiddleware(BlockBrowsers::class)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce/'.keyOf($user ?? $test->user)."?{$query}")
        ->assertOk()
        ->getContent());
}

function compact4(string $ip, int $port = 51413): string
{
    return inet_pton($ip).pack('n', $port);
}

describe('peers6 (BEP 7)', function () {
    test('an IPv4 seeder and an IPv6 leecher are each handed the other', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0);
        $leecher = announced6($this, '-qB4210-bbbbbbbbbbbb', '2001:db8::1', 5, ['left' => 500], $this->other);

        expect($leecher['peers'])->toBe(compact4('203.0.113.7'));

        $seeder = announced6($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 1_800, ['event' => '']);

        expect($seeder['peers'])->toBe('')
            ->and($seeder['peers6'])->toBe(compact4('2001:db8::1'));
    });

    // libtorrent announces once per listen interface: the same peer ID over
    // IPv4, then IPv6. Its record used to keep only the latest address.
    test('a client announcing over each family in turn is handed out in both', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0);
        announced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8::1', 5, ['event' => '']);

        $leecher = announced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', 10, ['left' => 500], $this->other);

        expect($leecher['peers'])->toBe(compact4('203.0.113.7'))
            ->and($leecher['peers6'])->toBe(compact4('2001:db8::1'));
    });

    test('an IPv4 client on a dual-stack listener (::ffff:a.b.c.d) is an IPv4 peer', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '::ffff:203.0.113.7', 0);
        $leecher = announced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', 5, ['left' => 500], $this->other);

        expect($leecher['peers'])->toBe(compact4('203.0.113.7'))
            ->and($leecher)->not->toHaveKey('peers6');
    });
});

describe('ipv4= and ipv6=', function () {
    // Back to testing before teardown: Testbench rolls the fixture migrations
    // back then, and in production a rollback asks for confirmation.
    afterEach(function () {
        $this->app['env'] = 'testing';
    });

    test('ipv4= from an IPv6 connection puts the peer in peers too, and goes on the ledger row', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8::1', 0, ['ipv4' => '203.0.113.7']);
        $leecher = announced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', 5, ['left' => 500], $this->other);

        expect($leecher['peers'])->toBe(compact4('203.0.113.7'))
            ->and($leecher['peers6'])->toBe(compact4('2001:db8::1'))
            ->and(AnnounceLog::query()->where('user_id', $this->user->id)->first())
            ->ip->toBe('2001:db8::1')
            ->announced_ipv4->toBe('203.0.113.7')
            ->announced_ipv6->toBeNull();
    });

    test('ipv6= from an IPv4 connection puts the peer in peers6', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0, ['ipv6' => '[2001:db8::1]:51413']);
        $leecher = announced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', 5, ['left' => 500], $this->other);

        expect($leecher['peers6'])->toBe(compact4('2001:db8::1'));
    });

    test('a claim for the family the client connected over is neither used nor recorded', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '198.51.100.9', 0, ['ipv4' => '203.0.113.7']);

        expect(AnnounceLog::query()->where('user_id', $this->user->id)->first()->announced_ipv4)->toBeNull();
    });

    test('an address of the wrong family, or not an address, is ignored', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8::1', 0, ['ipv4' => '2001:db8::2', 'ipv6' => 'nonsense']);
        $leecher = announced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', 5, ['left' => 500], $this->other);

        expect($leecher['peers'])->toBe('')
            ->and($leecher['peers6'])->toBe(compact4('2001:db8::1'))
            ->and(AnnounceLog::query()->where('user_id', $this->user->id)->first()->announced_ipv4)->toBeNull();
    });

    test('in production a private ipv4= is ignored, as a private connecting address is refused', function () {
        $this->app['env'] = 'production';
        // The test Redis is not durable, and production refuses that first.
        $this->app->instance(RedisDurability::class, new class extends RedisDurability
        {
            public function allowsAnnounces(): bool
            {
                return true;
            }
        });

        announced6($this, '-qB4210-aaaaaaaaaaaa', '2001:4860::8888', 0, ['ipv4' => '192.168.1.10']);
        $leecher = announced6($this, '-qB4210-bbbbbbbbbbbb', '8.8.4.4', 5, ['left' => 500], $this->other);

        expect($leecher['peers'])->toBe('');
    });

    test('with trust_announced_addresses off, a peer is handed out only where it announced from', function () {
        config(['bloodhound.trust_announced_addresses' => false]);

        announced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8::1', 0, ['ipv4' => '203.0.113.7']);
        $leecher = announced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', 5, ['left' => 500], $this->other);

        expect($leecher['peers'])->toBe('')
            ->and(AnnounceLog::query()->where('user_id', $this->user->id)->first()->announced_ipv4)->toBeNull();
    });
});

describe('locations: where a peer last connected from', function () {
    test('a second leeching client at a dual-stack client\'s IPv6 /64 is at the same place', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0, ['left' => 500]);
        announced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8:1:2::10', 120, ['left' => 500, 'event' => '']);

        expect(announced6($this, '-qB4210-bbbbbbbbbbbb', '2001:db8:1:2::99', 130, ['left' => 500]))->not->toHaveKey('failure reason');
    });

    // Found by the 10.1 docs read-through. A client can announce any address,
    // so one that claims the account owner's is not at the owner's house: it
    // is located where it connected from, and a second leeching location is
    // refused as it always was.
    test('an announced address never puts a client at the place of the member\'s other client', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0, ['left' => 500]);

        expect(announced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', 5, ['left' => 500, 'ipv4' => '203.0.113.7']))
            ->toHaveKey('failure reason')
            ->and(announced6($this, '-qB4210-cccccccccccc', '2001:db8:9::1', 10, ['left' => 500, 'ipv4' => '203.0.113.7']))
            ->toHaveKey('failure reason');
    });

    test('an ipv4= over an IPv4 connection doesn\'t replace the address it connected from', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '198.51.100.9', 0, ['ipv4' => '203.0.113.7']);
        $leecher = announced6($this, '-qB4210-bbbbbbbbbbbb', '192.0.2.44', 5, ['left' => 500], $this->other);

        expect($leecher['peers'])->toBe(compact4('198.51.100.9'));
    });

    // Found by the 10.1 third read-through. One peer ID announces from home
    // over IPv4, then from a friend's over IPv6; if its two families vouched
    // for each other, ordinary clients at both places would pass. A peer is
    // located where it last connected from, and nothing else.
    test('one peer ID announcing from two places over different families doesn\'t make them one place', function () {
        announced6($this, '-qB4210-xxxxxxxxxxxx', '203.0.113.7', 0, ['left' => 500]);
        announced6($this, '-qB4210-xxxxxxxxxxxx', '2001:db8:9::1', 60, ['left' => 500, 'event' => '']);

        expect(announced6($this, '-qB4210-yyyyyyyyyyyy', '203.0.113.7', 120, ['left' => 500]))->toHaveKey('failure reason');
    });

    test('a dual-stack client elsewhere counts once against the limit, whatever it announces', function () {
        config(['bloodhound.anti_cheat.max_seeding_locations_per_torrent' => 2]);

        announced6($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0, ['ipv6' => '2001:db8:1:2::10']);

        expect(announced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', 5))->not->toHaveKey('failure reason')
            ->and(announced6($this, '-qB4210-cccccccccccc', '192.0.2.44', 10))->toHaveKey('failure reason');
    });

    // Found by the 10.1 docs read-through: the family the peer no longer has
    // was carried forward for peer_expiry, so the move went uncounted.
    test('a dual-stack client restarting on an IPv4-only network elsewhere has moved', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0, ['left' => 500]);
        announced6($this, '-qB4210-aaaaaaaaaaaa', '2001:db8:1:2::10', 120, ['left' => 500, 'event' => '']);
        announced6($this, '-qB4210-bbbbbbbbbbbb', '2001:db8:1:2::99', 130, ['left' => 500]);

        // The first client moves to another network, IPv4 only. The member's
        // second client is still leeching at home, so this is a second place.
        expect(announced6($this, '-qB4210-aaaaaaaaaaaa', '192.0.2.44', 240, ['left' => 500, 'event' => '']))->toHaveKey('failure reason');
    });

    // Found by the 10.1 second read-through. A laptop leaves the dual-stack
    // home for an IPv4-only network elsewhere; the owner then leeches at home
    // over IPv6. The laptop's home IPv6 used to count as where it still was,
    // for an hour, or for as long as it kept claiming it with ipv6=.
    test('a client that left home doesn\'t keep home as one of its places, claimed or not', function () {
        foreach ([[], ['ipv6' => '2001:db8:1:2::10']] as $i => $claim) {
            Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
            $base = $i * 10_000;
            $laptop = sprintf('-qB4210-laptop%06d', $i);

            announced6($this, $laptop, '203.0.113.7', $base, ['left' => 500]);
            announced6($this, $laptop, '2001:db8:1:2::10', $base + 120, ['left' => 500, 'event' => '']);
            announced6($this, $laptop, '198.51.100.9', $base + 240, ['left' => 500, 'event' => ''] + $claim);
            announced6($this, $laptop, '198.51.100.9', $base + 2_000, ['left' => 500, 'event' => ''] + $claim);

            expect(announced6($this, sprintf('-qB4210-home%08d', $i), '2001:db8:1:2::99', $base + 2_010, ['left' => 500]))
                ->toHaveKey('failure reason');
        }
    });

    test('a client that moves to a new place on both families has moved', function () {
        announced6($this, '-qB4210-aaaaaaaaaaaa', '203.0.113.7', 0, ['left' => 500]);
        announced6($this, '-qB4210-bbbbbbbbbbbb', '198.51.100.9', 5, ['left' => 500], $this->other);

        // The member's second client, leeching somewhere else: refused.
        expect(announced6($this, '-qB4210-cccccccccccc', '2001:db8:9::1', 10, ['left' => 500]))->toHaveKey('failure reason');
    });
});
