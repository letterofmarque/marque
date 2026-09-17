<?php

declare(strict_types=1);

// Job #10649, the TBDev/Gazelle shape: announce.php?passkey=<key>. This is the
// single most common thing a Laravel tracker would be migrating FROM.
//
// The interesting part is the failure surface, and it is the job's design point
// 2. With the key in the path, the route constraint 404s a malformed key and no
// application code runs. As a query parameter every request reaches PHP, so the
// refusal has to come from the controller — and it answers with a bencoded
// 'failure reason' at HTTP 200, the same shape as every other tracker error,
// because a 4xx reads to a BitTorrent client as an unreachable tracker rather
// than as a message worth showing the user.

const QUERY_KEY = 'aaaabbbbccccddddeeeeffffgggghhhh';

it('reads the key from the query string', function () {
    makeTrackerUser(QUERY_KEY, 'query@example.com');

    // No announce parameters are sent, so the furthest this can reach is the
    // parameter check that runs AFTER the key was accepted and the user found.
    // "Missing parameter" is therefore proof the key was read and resolved.
    $response = trackerRequest($this, '/announce.php?passkey='.QUERY_KEY);

    expect($response->getStatusCode())->toBe(200);
    expect(decodeTracker($response)['failure reason'])->toStartWith('Missing parameter');
});

it('rejects a well-formed key belonging to nobody', function () {
    $response = trackerRequest($this, '/announce.php?passkey=zzzzyyyyxxxxwwwwvvvvuuuuttttssss');

    expect(decodeTracker($response)['failure reason'])->toBe('Unknown announce key');
});

it('answers a malformed key with a bencoded failure, not a 404', function () {
    $response = trackerRequest($this, '/announce.php?passkey=junk');

    expect($response->getStatusCode())->toBe(200);
    expect(decodeTracker($response)['failure reason'])->toBe('Invalid announce key');
});

it('answers a missing key parameter the same way', function () {
    // In path mode the router would refuse this outright. Here it reaches the
    // controller, and an absent key must not read as an empty-string key.
    $response = trackerRequest($this, '/announce.php');

    expect($response->getStatusCode())->toBe(200);
    expect(decodeTracker($response)['failure reason'])->toBe('Invalid announce key');
});

it('does not also accept the key as a path segment', function () {
    // Both shapes serving at once would mean the old URL quietly still works,
    // which hides a failed migration.
    expect(trackerRequest($this, '/announce.php/'.QUERY_KEY)->getStatusCode())->toBe(404);
});

it('applies the same rules to scrape', function () {
    makeTrackerUser(QUERY_KEY, 'scrape-query@example.com');

    // Changing announce and not scrape is a half-migration, so scrape reads the
    // query key too — while still allowing a keyless public scrape.
    expect(trackerRequest($this, '/scrape.php?passkey='.QUERY_KEY)->getStatusCode())->toBe(200);
    expect(trackerRequest($this, '/scrape.php')->getStatusCode())->toBe(200);
});

it('refuses a malformed scrape key rather than ignoring it', function () {
    $response = trackerRequest($this, '/scrape.php?passkey=junk');

    expect(decodeTracker($response)['failure reason'])->toBe('Invalid announce key');
});
