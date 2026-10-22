<?php

declare(strict_types=1);

// #11070. From 9.0 a malformed key in the path gets a bencoded failure by
// default (TrackerRoutesTest). routes.malformed_key = 'not_found' keeps the
// router's 404, which 8.x documented, for an install that relies on it.

const NOT_FOUND_GOOD_KEY = 'goodgoodgoodgoodgoodgoodgoodgood';

it('answers a malformed announce key with the router\'s 404', function (string $path) {
    expect(trackerRequest($this, $path)->getStatusCode())->toBe(404);
})->with([
    'too short' => '/announce/invalid',
    'too long' => '/announce/'.str_repeat('a', 40),
    'extra segments' => '/announce/a/b',
    'no key at all' => '/announce',
]);

it('answers a malformed scrape key with the router\'s 404', function () {
    expect(trackerRequest($this, '/scrape/invalid')->getStatusCode())->toBe(404);
});

it('still sends a well-formed key down the named route', function () {
    makeTrackerUser(NOT_FOUND_GOOD_KEY, 'notfound@example.com');

    expect(decodeTracker(trackerRequest($this, '/announce/'.NOT_FOUND_GOOD_KEY))['failure reason'])
        ->not->toBe('Invalid announce key');
});

it('still serves a public scrape with no key', function () {
    expect(trackerRequest($this, '/scrape')->getStatusCode())->toBe(200);
});
