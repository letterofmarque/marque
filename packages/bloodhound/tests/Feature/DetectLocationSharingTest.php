<?php

declare(strict_types=1);

// Job #319 step 3: bloodhound:detect-location-sharing reads the ledger for
// the patterns no single announce shows. It reports and fires events; it
// never refuses or changes a credit. Built from real announces.

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Events\CheatDetected;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\LocationSharingDetector;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => true]);

    $this->user = TestUser::create(['name' => 'Det', 'email' => 'det@example.com', 'password' => 'p']);
    issueAnnounceKey($this->user, 'detdetdetdetdetdetdetdetdetdet00');
    $this->torrent = Torrent::create([
        'name' => 'Det', 'info_hash' => str_repeat('d', 40), 'size' => 1_000_000_000, 'user_id' => $this->user->id,
    ]);

    $this->t = Carbon::parse('2026-11-02 00:00:00');
});

/**
 * @return array<string, mixed>
 */
function detected($test, string $peer, string $ip, int $seconds, array $params = []): array
{
    $test->travelTo($test->t->copy()->addSeconds($seconds));

    return Bencode::decode($test->withoutMiddleware(BlockBrowsers::class)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get('/announce/'.keyOf($test->user).'?'.http_build_query(array_merge([
            'info_hash' => hex2bin($test->torrent->info_hash), 'peer_id' => $peer, 'port' => 51413,
            'uploaded' => 0, 'downloaded' => 0, 'left' => 500, 'compact' => 1, 'event' => '',
        ], $params)))
        ->assertOk()
        ->getContent());
}

function findings($test, int $at, int $linkDays = 7): array
{
    $test->travelTo($test->t->copy()->addSeconds($at));

    return app(LocationSharingDetector::class)->detect(Carbon::now()->subDay(), Carbon::now()->subDays($linkDays));
}

/**
 * The residual hole under flag, set up at $at: a helper at home moves to a
 * friend's over the other family, and a client there joins it.
 */
function linkedSetup($test, int $at): void
{
    detected($test, '-qB4210-xxxxxxxxxxxx', '203.0.113.7', $at, ['event' => 'started']);
    detected($test, '-qB4210-yyyyyyyyyyyy', '203.0.113.7', $at + 10, ['event' => 'started']);
    detected($test, '-qB4210-xxxxxxxxxxxx', '2001:db8:9::1', $at + 20);
    detected($test, '-qB4210-zzzzzzzzzzzz', '2001:db8:9::2', $at + 30, ['event' => 'started']);
}

/**
 * Y at home and Z at the friend's keep announcing, from $from for $hours.
 */
function keepLeeching($test, int $from, int $hours): void
{
    foreach (range(0, $hours * 2 - 1) as $i) {
        detected($test, '-qB4210-yyyyyyyyyyyy', '203.0.113.7', $from + $i * 1_800);
        detected($test, '-qB4210-zzzzzzzzzzzz', '2001:db8:9::2', $from + $i * 1_800 + 5);
    }
}

test('a dual-stack household switching families every interval is not reported', function () {
    detected($this, '-qB4210-laptop000000', '203.0.113.7', 0, ['event' => 'started']);
    detected($this, '-qB4210-desktop00000', '203.0.113.7', 10, ['event' => 'started']);

    foreach (range(0, 5) as $i) {
        $at = 15 + $i * 1_800;
        detected($this, '-qB4210-desktop00000', '2001:db8:1:2::10', $at);
        detected($this, '-qB4210-laptop000000', '203.0.113.7', $at + 900);
        detected($this, '-qB4210-desktop00000', '203.0.113.7', $at + 1_795);
    }

    expect(AnnounceLog::query()->where('anti_cheat_reason', 'like', 'Ambiguous location%')->count())->toBeGreaterThan(0)
        ->and(findings($this, 12_000))->toBe([]);
});

// Both clients dual-stack: the member has peers at both of the link's places,
// so only the switching tells this apart from the attack below.
test('a household of two dual-stack clients, both switching families, is not reported', function () {
    detected($this, '-qB4210-laptop000000', '203.0.113.7', 0, ['event' => 'started']);
    detected($this, '-qB4210-desktop00000', '203.0.113.7', 10, ['event' => 'started']);

    foreach (range(0, 5) as $i) {
        $at = 15 + $i * 1_800;
        detected($this, '-qB4210-desktop00000', '2001:db8:1:2::10', $at);
        detected($this, '-qB4210-laptop000000', '2001:db8:1:2::20', $at + 5);
        detected($this, '-qB4210-laptop000000', '203.0.113.7', $at + 900);
        detected($this, '-qB4210-desktop00000', '203.0.113.7', $at + 1_795);
    }

    expect(AnnounceLog::query()->where('anti_cheat_reason', 'like', 'Ambiguous location%')->count())->toBeGreaterThan(0)
        ->and(findings($this, 12_000))->toBe([]);
});

// One switch and no more, but nobody else of the member's ever announced
// from the IPv6 end: a desktop whose IPv4 endpoint went away, not a link to
// another place.
test('a link that isn\'t sustained, without other peers at both ends, is not reported', function () {
    detected($this, '-qB4210-laptop000000', '203.0.113.7', 0, ['event' => 'started']);
    detected($this, '-qB4210-desktop00000', '203.0.113.7', 10, ['event' => 'started']);
    detected($this, '-qB4210-desktop00000', '2001:db8:1:2::10', 15);

    foreach ([1_800, 3_600, 5_400] as $at) {
        detected($this, '-qB4210-laptop000000', '203.0.113.7', $at);
        detected($this, '-qB4210-desktop00000', '2001:db8:1:2::10', $at + 5);
    }

    expect(findings($this, 6_000))->toBe([]);
});

// The residual hole under flag: a helper peer at home moves to a friend's
// over the other family (one flagged row), then a client at the friend's
// joins it cleanly, and the helper stops switching.
test('a link set up once and never sustained, with other peers at both ends, is reported', function () {
    detected($this, '-qB4210-xxxxxxxxxxxx', '203.0.113.7', 0, ['event' => 'started']);
    detected($this, '-qB4210-yyyyyyyyyyyy', '203.0.113.7', 10, ['event' => 'started']);
    expect(detected($this, '-qB4210-xxxxxxxxxxxx', '2001:db8:9::1', 20))->not->toHaveKey('failure reason');
    expect(detected($this, '-qB4210-zzzzzzzzzzzz', '2001:db8:9::2', 30, ['event' => 'started']))->not->toHaveKey('failure reason');

    foreach ([1_800, 3_600, 5_400] as $at) {
        detected($this, '-qB4210-yyyyyyyyyyyy', '203.0.113.7', $at);
        detected($this, '-qB4210-zzzzzzzzzzzz', '2001:db8:9::2', $at + 5);
    }

    $found = findings($this, 6_000);

    expect($found)->toHaveCount(1)
        ->and($found[0])->toMatchArray(['kind' => 'unsustained_link', 'user_id' => $this->user->id, 'torrent_id' => $this->torrent->id, 'peer_id' => '-qB4210-xxxxxxxxxxxx'])
        ->and($found[0]['places'])->toBe(['203.0.113.7', '20010db800090000::/64']);
});

// Found by the 11.0 review: only the setup day was ever read, so a setup
// that survived one day was never reported again.
test('a link set up days ago is still reported while the member keeps both places', function () {
    linkedSetup($this, 0);
    keepLeeching($this, 1_800, 74);

    $found = findings($this, 75 * 3_600);

    expect($found)->toHaveCount(1)
        ->and($found[0]['kind'])->toBe('unsustained_link')
        ->and(findings($this, 75 * 3_600, linkDays: 1))->toBe([]);
});

test('a link set up across the window\'s edge is still a link', function () {
    detected($this, '-qB4210-xxxxxxxxxxxx', '203.0.113.7', 0, ['event' => 'started']);
    detected($this, '-qB4210-yyyyyyyyyyyy', '203.0.113.7', 10, ['event' => 'started']);
    detected($this, '-qB4210-xxxxxxxxxxxx', '203.0.113.7', 1_800);
    detected($this, '-qB4210-yyyyyyyyyyyy', '203.0.113.7', 1_810);

    // The window opens at 3,600: X's last home announce is before it, the
    // move after.
    detected($this, '-qB4210-xxxxxxxxxxxx', '2001:db8:9::1', 3_620);
    detected($this, '-qB4210-zzzzzzzzzzzz', '2001:db8:9::2', 3_630, ['event' => 'started']);
    keepLeeching($this, 3_640, 2);

    $this->travelTo($this->t->copy()->addSeconds(11_000));
    $found = app(LocationSharingDetector::class)->detect($this->t->copy()->addSeconds(3_600), $this->t->copy()->addSeconds(3_600));

    expect($found)->toHaveCount(1)
        ->and($found[0]['places'])->toBe(['203.0.113.7', '20010db800090000::/64']);
});

test('one peer ID going back and forth between two IPv4 networks is reported', function () {
    foreach (['203.0.113.7', '198.51.100.9', '203.0.113.7', '198.51.100.9'] as $i => $ip) {
        expect(detected($this, '-qB4210-xxxxxxxxxxxx', $ip, $i * 1_800, $i === 0 ? ['event' => 'started'] : []))->not->toHaveKey('failure reason');
    }

    $found = findings($this, 7_000);

    expect($found)->toHaveCount(1)
        ->and($found[0])->toMatchArray(['kind' => 'alternating_peer', 'count' => 2]);
});

test('a peer that moves once is not reported', function () {
    detected($this, '-qB4210-xxxxxxxxxxxx', '203.0.113.7', 0, ['event' => 'started']);
    detected($this, '-qB4210-xxxxxxxxxxxx', '198.51.100.9', 1_800);
    detected($this, '-qB4210-xxxxxxxxxxxx', '198.51.100.9', 3_600);

    expect(findings($this, 4_000))->toBe([]);
});

test('refused announces place nobody, so they never make a finding', function () {
    detected($this, '-qB4210-yyyyyyyyyyyy', '203.0.113.7', 0, ['event' => 'started']);

    // Within peer_expiry of the leecher at home, so every one is refused.
    foreach (['198.51.100.9', '192.0.2.44', '198.51.100.9', '192.0.2.44'] as $i => $ip) {
        expect(detected($this, '-qB4210-xxxxxxxxxxxx', $ip, 10 + $i * 300, ['event' => 'started']))->toHaveKey('failure reason');
    }

    expect(findings($this, 2_000))->toBe([]);
});

test('only the window is read', function () {
    foreach (['203.0.113.7', '198.51.100.9', '203.0.113.7', '198.51.100.9'] as $i => $ip) {
        detected($this, '-qB4210-xxxxxxxxxxxx', $ip, $i * 1_800, $i === 0 ? ['event' => 'started'] : []);
    }

    expect(findings($this, 2 * 86_400))->toBe([]);
});

test('the command reports each finding, fires an event, and changes no row', function () {
    Event::fake([CheatDetected::class]);

    foreach (['203.0.113.7', '198.51.100.9', '203.0.113.7', '198.51.100.9'] as $i => $ip) {
        detected($this, '-qB4210-xxxxxxxxxxxx', $ip, $i * 1_800, $i === 0 ? ['event' => 'started'] : []);
    }

    $before = AnnounceLog::query()->orderBy('id')->get()->toArray();
    $this->travelTo($this->t->copy()->addSeconds(7_000));

    $this->artisan('bloodhound:detect-location-sharing')
        ->expectsOutputToContain('went back to a network it had left 2 time(s)')
        ->assertSuccessful();

    expect(AnnounceLog::query()->orderBy('id')->get()->toArray())->toBe($before);
    Event::assertDispatched(CheatDetected::class, fn (CheatDetected $e) => $e->type === 'location_sharing_suspected' && $e->userId === $this->user->id);
});

test('an empty ledger is quiet', function () {
    $this->artisan('bloodhound:detect-location-sharing')
        ->expectsOutputToContain('No location sharing suspected')
        ->assertSuccessful();
});

test('it is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'bloodhound:detect-location-sharing'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
