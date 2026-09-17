<?php

declare(strict_types=1);

// Job #10649: hound's announce and scrape paths are configurable for the same
// reason bloodhound's are — a public tracker migrating onto Marque cannot change
// the URL its circulating .torrent files announce to.
//
// No key options here: hound is keyless by design, so there is no pattern and no
// key_source to test. Paths only.

use Illuminate\Testing\TestResponse;
use Marque\Threepio\Http\Middleware\BlockBrowsers;

function houndGet($test, string $url): TestResponse
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get($url);
}

it('serves announce at the configured path', function () {
    // No info_hash, so this is a bencoded failure rather than a successful
    // announce — but a 200 proves the route resolved, which is what is under
    // test. A routing miss would be 404.
    expect(houndGet($this, '/announce.php')->getStatusCode())->toBe(200);
});

it('serves scrape at the configured path', function () {
    expect(houndGet($this, '/scrape.php')->getStatusCode())->toBe(200);
});

it('stops serving the default paths', function () {
    // An old URL left live hides the fact that the config did nothing.
    expect(houndGet($this, '/announce')->getStatusCode())->toBe(404);
    expect(houndGet($this, '/scrape')->getStatusCode())->toBe(404);
});

it('keeps the route names stable', function () {
    expect(route('tracker.announce'))->toContain('announce.php');
    expect(route('tracker.scrape'))->toContain('scrape.php');
});
