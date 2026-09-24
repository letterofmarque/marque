<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Tests\CustomPathTestCase;
use Marque\Bloodhound\Tests\CustomPatternTestCase;
use Marque\Bloodhound\Tests\QueryKeyTestCase;
use Marque\Bloodhound\Tests\RatioModeFullTestCase;
use Marque\Bloodhound\Tests\RatioModeOffTestCase;
use Marque\Bloodhound\Tests\RatioModeSeedtimeTestCase;
use Marque\Bloodhound\Tests\TestCase;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Contracts\TrackerStatsInterface;

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

// Spec #118 CP3: panels register in the provider's boot(), so ratio_mode must
// be set before the app boots — a directory per mode, same reason the routing
// TestCases above exist.
pest()->extend(RatioModeFullTestCase::class)->in('PanelsFull', 'StatsFull');
pest()->extend(RatioModeOffTestCase::class)->in('PanelsOff', 'StatsOff');
pest()->extend(RatioModeSeedtimeTestCase::class)->in('PanelsSeedtime', 'StatsSeedtime');

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
