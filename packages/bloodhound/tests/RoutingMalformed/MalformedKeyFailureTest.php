<?php

declare(strict_types=1);

// #11070 item 11: with the key in the path, a malformed one was the app's HTML
// 404, which a client shows as a garbled or unknown error instead of a reason.
// routes.malformed_key = 'failure' answers it the way every other tracker error
// is answered. Opt-in in 8.x because the 404 was documented behaviour.

const MALFORMED_GOOD_KEY = 'goodgoodgoodgoodgoodgoodgoodgood';

it('answers a malformed announce key with a bencoded failure', function (string $path) {
    $response = trackerRequest($this, $path);

    expect($response->getStatusCode())->toBe(200)
        ->and(decodeTracker($response)['failure reason'])->toBe('Invalid announce key');
})->with([
    'too short' => '/announce/invalid',
    'too long' => '/announce/'.str_repeat('a', 40),
    'extra segments' => '/announce/a/b',
    'no key at all' => '/announce',
]);

it('answers a malformed scrape key with a bencoded failure', function () {
    $response = trackerRequest($this, '/scrape/invalid');

    expect($response->getStatusCode())->toBe(200)
        ->and(decodeTracker($response)['failure reason'])->toBe('Invalid announce key');
});

it('still sends a well-formed key down the named route', function () {
    makeTrackerUser(MALFORMED_GOOD_KEY, 'malformed@example.com');

    // Reaching the controller with a known key and no parameters fails on the
    // parameters, not the key.
    expect(decodeTracker(trackerRequest($this, '/announce/'.MALFORMED_GOOD_KEY))['failure reason'])
        ->not->toBe('Invalid announce key')
        ->and(route('tracker.announce', ['announce_key' => MALFORMED_GOOD_KEY]))->toEndWith('/announce/'.MALFORMED_GOOD_KEY);
});

it('still serves a public scrape with no key', function () {
    expect(trackerRequest($this, '/scrape')->getStatusCode())->toBe(200);
});
