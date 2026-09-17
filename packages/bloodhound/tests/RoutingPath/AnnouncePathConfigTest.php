<?php

declare(strict_types=1);

// Job #10649: the announce path is configurable, because a tracker migrating
// onto Marque cannot change the URL its circulating .torrent files announce to —
// those files are on strangers' disks and cannot be reissued.
//
// The config is applied in defineEnvironment (see CustomPathTestCase), not in a
// beforeEach: routes register during the provider's boot(), so anything set
// later binds nothing and the assertions would pass or fail for the wrong reason.

const PATH_KEY = 'aaaabbbbccccddddeeeeffffgggghhhh';

it('serves announce at the configured path', function () {
    makeTrackerUser(PATH_KEY, 'path@example.com');

    $response = trackerRequest($this, '/announce.php/'.PATH_KEY);

    // 200 with a bencoded failure means the controller was reached — a routing
    // miss would be a 404 with no body worth decoding.
    expect($response->getStatusCode())->toBe(200);
    expect(decodeTracker($response))->toHaveKey('failure reason');
});

it('serves scrape at the configured path', function () {
    expect(trackerRequest($this, '/scrape.php')->getStatusCode())->toBe(200);
});

it('stops serving the default path', function () {
    // A half-migration that leaves the old URL live is worse than no migration:
    // it hides the fact that the config did nothing.
    expect(trackerRequest($this, '/announce/'.PATH_KEY)->getStatusCode())->toBe(404);
});

it('keeps the route names stable', function () {
    // Consumers reference these names. Moving the path must not move the name.
    expect(route('tracker.announce', ['announce_key' => PATH_KEY]))
        ->toContain('announce.php/'.PATH_KEY);

    expect(route('tracker.scrape'))->toContain('scrape.php');
});
