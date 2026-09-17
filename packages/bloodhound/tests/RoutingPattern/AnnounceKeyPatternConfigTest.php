<?php

declare(strict_types=1);

// Job #10649 design point 1: the key format used to be asserted in FOUR places
// — the announce route constraint, the scrape route constraint, and a preg_match
// in each of the two controllers — each spelling out [0-9a-zA-Z]{32} on its own.
//
// Harmless while hardcoded and identical. Fatal once configurable: a consumer
// widening the pattern would widen whichever copies they found, and the router
// would start accepting keys the controller then rejects, or the reverse. These
// tests exist to prove the four collapsed into one (AnnounceRouting).

it('accepts a key matching the configured pattern', function () {
    $key = str_repeat('a1b2', 10); // 40 hex chars

    makeTrackerUser($key, 'pattern@example.com');

    $response = trackerRequest($this, '/announce/'.$key);

    expect($response->getStatusCode())->toBe(200);
    expect(decodeTracker($response))->toHaveKey('failure reason');
});

it('rejects a key that only satisfies the old default', function () {
    // 32 alphanumerics: valid under the default pattern, invalid under this one.
    // This is the assertion that proves the configured pattern is genuinely in
    // force rather than the default quietly still applying somewhere.
    expect(trackerRequest($this, '/announce/aaaabbbbccccddddeeeeffffgggghhhh')->getStatusCode())
        ->toBe(404);
});

it('applies the same pattern to scrape', function () {
    // The router refuses it, so scrape and announce cannot drift apart.
    expect(trackerRequest($this, '/scrape/aaaabbbbccccddddeeeeffffgggghhhh')->getStatusCode())
        ->toBe(404);

    expect(trackerRequest($this, '/scrape/'.str_repeat('a1b2', 10))->getStatusCode())
        ->toBe(200);
});
