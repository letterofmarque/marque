<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Tests\CustomPathTestCase;
use Marque\Bloodhound\Tests\CustomPatternTestCase;
use Marque\Bloodhound\Tests\QueryKeyTestCase;
use Marque\Bloodhound\Tests\RatioModeFullTestCase;
use Marque\Bloodhound\Tests\RatioModeOffTestCase;
use Marque\Bloodhound\Tests\RatioModeSeedtimeTestCase;
use Marque\Bloodhound\Tests\TestCase;
use Marque\Bloodhound\Tests\OutsideTransactionTestCase;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Models\Torrent;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

// Job #10649: the announce URL's shape is configurable, and routes register
// during the service provider's boot() — so the config has to be in place
// BEFORE the application boots. That means a TestCase per configuration
// (defineEnvironment is the only hook early enough) and therefore a directory
// per configuration, since Pest binds one test case per directory.
//
// Setting the config from a beforeEach instead would bind nothing: the routes
// are already registered against the old values, and the assertions would pass
// or fail for reasons unrelated to what they claim to test.
pest()->extend(CustomPathTestCase::class)->in('RoutingPath');
pest()->extend(QueryKeyTestCase::class)->in('RoutingQuery');
pest()->extend(CustomPatternTestCase::class)->in('RoutingPattern');

// Spec #119: the stats binding must hold in every ratio_mode, and a binding is
// decided before boot — so ratio_mode is set before the app boots, a directory
// per mode, same reason the routing TestCases above exist.
pest()->extend(RatioModeFullTestCase::class)->in('StatsFull');
pest()->extend(RatioModeOffTestCase::class)->in('StatsOff');
pest()->extend(RatioModeSeedtimeTestCase::class)->in('StatsSeedtime');

// Job #193: tests outside the per-test transaction: ones that migrate the
// schema themselves (DDL commits on MySQL), and ones that hold a lock from a
// second connection (which sees only committed rows).
pest()->extend(OutsideTransactionTestCase::class)->in('UpgradePath', 'Concurrency');

/**
 * Spec #119: the tracker stats contract reports what is stored, in every
 * ratio_mode, because nothing reads ratio_mode (job #10732) and the ledger
 * accumulates identically in all three. Run from a directory per mode so the
 * mode is in place before boot — a binding made conditional on ratio_mode at
 * registration is exactly the mistake this is here to catch, and a
 * config()->set() inside the test would be too late to see it.
 */
function assertStatsReportedRegardlessOfRatioMode(): void
{
    $user = makeTrackerUser('mmmmnnnnooooppppqqqqrrrrsssstttt', 'mode@example.com');
    $user->forceFill(['uploaded' => 4_000, 'downloaded' => 1_000, 'seedtime' => 600])->save();

    expect(app()->bound(TrackerStatsInterface::class))->toBeTrue();

    $stats = app(TrackerStatsInterface::class)->statsFor($user);

    expect($stats)->not->toBeNull()
        ->and($stats->uploaded)->toBe(4_000)
        ->and($stats->downloaded)->toBe(1_000)
        ->and($stats->seedtime)->toBe(600)
        ->and($stats->ratio)->toBe(4.0);
}

/**
 * Make a tracker request the way a BitTorrent client would.
 *
 * BlockBrowsers is dropped because the test client sends browser-ish headers it
 * would reject, and a real client's user agent is supplied so anything sniffing
 * it behaves as it would in production.
 */
function trackerRequest($test, string $url): TestResponse
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get($url);
}

/**
 * Decode a bencoded tracker response body.
 *
 * @return array<string, mixed>
 */
function decodeTracker(TestResponse $response): array
{
    return Bencode::decode($response->getContent());
}

function makeTrackerUser(string $announceKey, string $email): TestUser
{
    $user = TestUser::create([
        'name' => 'Test User',
        'email' => $email,
        'password' => 'password',
    ]);

    issueAnnounceKey($user, $announceKey);

    return $user;
}

/**
 * Give a user a specific announce key, in the table bloodhound owns.
 *
 * Tests want known keys; production mints them through TrackerStatsService.
 * users.announce_key is deprecated and never read, so setting it does nothing.
 */
function issueAnnounceKey(TestUser $user, string $key): void
{
    AnnounceKey::query()->forceCreate(['user_id' => $user->getKey(), 'key' => $key]);
}

/**
 * The user's current announce key, asked the way a consumer asks.
 */
function keyOf(TestUser $user): string
{
    return (string) app(TrackerStatsInterface::class)->announceKeyFor($user);
}

/**
 * Announce as a real client would, at a given moment.
 *
 * Travels the clock first, so announce_log.created_at is the moment given:
 * the row is written through Eloquent, which stamps it with now(). Used where
 * seedtime has to be reached through the tracker itself rather than by
 * writing ledger rows (Spec #121 criterion 1).
 */
function announceAt(TestUser $user, Torrent $torrent, Carbon $at, int $left, ?string $event = null, string $peerId = '-qB4210-aaaaaaaaaaaa'): void
{
    test()->travelTo($at);

    $query = http_build_query(array_filter([
        'info_hash' => hex2bin($torrent->info_hash),
        'peer_id' => $peerId,
        'port' => 51413,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => $left,
        'event' => $event,
        'compact' => 1,
    ], fn ($value) => $value !== null));

    trackerRequest(test(), '/announce/'.keyOf($user)."?{$query}")->assertOk();
}

/**
 * Seconds seeded and leeched, as both projections hold them.
 *
 * @return array{user: array{int, int}, torrent: array{int, int}}
 */
function timeTotals(TestUser $user, Torrent $torrent): array
{
    $row = DB::table($user->getTable())->where('id', $user->getKey())->first();
    $torrentUser = TorrentUser::query()
        ->where('user_id', $user->getKey())
        ->where('torrent_id', $torrent->id)
        ->first();

    return [
        'user' => [(int) $row->seedtime, (int) $row->leechtime],
        'torrent' => [(int) $torrentUser?->seedtime, (int) $torrentUser?->leechtime],
    ];
}

/**
 * Spec #121: seedtime is an observation, not a policy, so it accrues the same
 * in every ratio_mode. Run from a directory per mode (mode set before boot),
 * each expecting the SAME figures: gating collection on ratio_mode would
 * discard history the moment an operator changed it.
 */
function assertTimeAccruesRegardlessOfRatioMode(): void
{
    Redis::connection(config('threepio.redis.connection', 'default'))->flushdb();
    config(['bloodhound.anti_cheat.enabled' => false]);

    $user = makeTrackerUser('uuuuvvvvwwwwxxxxyyyyzzzz00001111', 'time-mode@example.com');
    $torrent = Torrent::create([
        'name' => 'Mode Torrent',
        'info_hash' => str_repeat('d', 40),
        'size' => 1_000,
        'user_id' => $user->id,
    ]);

    $t = Carbon::parse('2026-10-01 10:00:00');

    announceAt($user, $torrent, $t, 1_000, 'started');
    announceAt($user, $torrent, $t->copy()->addMinutes(30), 0, 'completed');
    announceAt($user, $torrent, $t->copy()->addMinutes(60), 0);

    app(LedgerAggregator::class)->run();

    expect(timeTotals($user, $torrent))->toBe([
        'user' => [1800, 1800],
        'torrent' => [1800, 1800],
    ]);
}
