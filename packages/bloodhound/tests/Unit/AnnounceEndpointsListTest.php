<?php

declare(strict_types=1);

// Job #109 step 1: the endpoint list, as AnnounceRouting builds it. The routes
// file registers exactly this list, so a list it refuses is a boot it refuses.

use Marque\Bloodhound\Support\AnnounceEndpoint;
use Marque\Bloodhound\Support\AnnounceRouting;

it('puts the primary endpoint first, from bloodhound.routes, and the others after it', function () {
    config(['bloodhound.endpoints' => ['legacy' => ['announce_path' => 'announce.php', 'scrape_path' => 'scrape.php', 'key_source' => 'query', 'key_parameter' => 'passkey']]]);

    $endpoints = AnnounceRouting::endpoints();

    expect(array_keys($endpoints))->toBe([AnnounceRouting::PRIMARY, 'legacy'])
        ->and($endpoints[AnnounceRouting::PRIMARY]->announcePath)->toBe('announce')
        ->and($endpoints['legacy']->keyIsInQuery())->toBeTrue()
        ->and($endpoints['legacy']->keyParameter)->toBe('passkey');
});

it('is just the primary endpoint when none are configured', function () {
    config(['bloodhound.endpoints' => []]);

    expect(array_keys(AnnounceRouting::endpoints()))->toBe([AnnounceRouting::PRIMARY]);
});

it('gives an endpoint the same defaults and normalisation as bloodhound.routes', function () {
    config(['bloodhound.endpoints' => ['other' => ['announce_path' => '/old/announce/', 'scrape_path' => 'old/scrape', 'key_source' => 'typo']]]);

    $other = AnnounceRouting::endpoints()['other'];

    expect($other->announcePath)->toBe('old/announce')
        ->and($other->keySource)->toBe('path')
        ->and($other->keyParameter)->toBe('announce_key')
        ->and($other->keyPattern)->toBe('[0-9a-zA-Z]{32}')
        ->and($other->malformedKey)->toBe('failure');
});

it('anchors each endpoint\'s own pattern', function () {
    $endpoint = AnnounceEndpoint::fromConfig('legacy', ['key_pattern' => '[0-9a-f]{32}']);

    expect($endpoint->matches(str_repeat('a', 32)))->toBeTrue()
        ->and($endpoint->matches('x'.str_repeat('a', 32)))->toBeFalse()
        ->and($endpoint->matches(str_repeat('g', 32)))->toBeFalse();
});

// A silently shadowed endpoint is a migration that looks configured and
// serves nothing: Laravel lets the first-registered route win without a word.
describe('collisions', function () {
    it('refuses two endpoints on one path, naming both', function (array $endpoints, string $path) {
        config(['bloodhound.endpoints' => $endpoints]);

        expect(fn () => AnnounceRouting::endpoints())->toThrow(InvalidArgumentException::class, $path);
    })->with([
        'an additional endpoint on the primary\'s announce path' => [['clash' => ['announce_path' => 'announce', 'scrape_path' => 'other']], '"announce"'],
        'one\'s scrape path on another\'s announce path' => [['a' => ['announce_path' => 'a', 'scrape_path' => 'b'], 'b' => ['announce_path' => 'b', 'scrape_path' => 'c']], '"b"'],
        'the same path written with slashes' => [['a' => ['announce_path' => 'x.php', 'scrape_path' => 'y.php'], 'b' => ['announce_path' => '/x.php/', 'scrape_path' => 'z.php']], '"x.php"'],
        'one endpoint\'s announce and scrape on one path' => [['self' => ['announce_path' => 'same', 'scrape_path' => 'same']], '"same"'],
    ]);

    it('names both endpoints in the message', function () {
        config(['bloodhound.endpoints' => ['clash' => ['announce_path' => 'announce', 'scrape_path' => 'other']]]);

        expect(fn () => AnnounceRouting::endpoints())->toThrow(InvalidArgumentException::class, 'primary');

        try {
            AnnounceRouting::endpoints();
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('clash');
        }
    });
});

it('refuses an endpoint name that cannot be part of a route name', function () {
    config(['bloodhound.endpoints' => ['old tracker' => ['announce_path' => 'x', 'scrape_path' => 'y']]]);

    AnnounceRouting::endpoints();
})->throws(InvalidArgumentException::class, 'old tracker');

it('refuses an additional endpoint named like the primary', function () {
    config(['bloodhound.endpoints' => [AnnounceRouting::PRIMARY => ['announce_path' => 'x', 'scrape_path' => 'y']]]);

    AnnounceRouting::endpoints();
})->throws(InvalidArgumentException::class);
