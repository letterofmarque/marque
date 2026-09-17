<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Marque\Bloodhound\Tests\CustomPathTestCase;
use Marque\Bloodhound\Tests\CustomPatternTestCase;
use Marque\Bloodhound\Tests\QueryKeyTestCase;
use Marque\Bloodhound\Tests\TestCase;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;

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
    return TestUser::create([
        'name' => 'Test User',
        'email' => $email,
        'password' => 'password',
        'announce_key' => $announceKey,
    ]);
}
