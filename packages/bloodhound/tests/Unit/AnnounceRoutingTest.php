<?php

declare(strict_types=1);

// The single source of truth for the announce URL's shape (job #10649). These
// are the edge cases that decide whether a misconfiguration fails safe, and they
// need no route table — only the config.

use Illuminate\Http\Request;
use Marque\Bloodhound\Support\AnnounceRouting;

const DEFAULT_KEY = 'aaaabbbbccccddddeeeeffffgggghhhh';

describe('key matching', function () {
    it('accepts a key matching the configured pattern', function () {
        expect(AnnounceRouting::matches(DEFAULT_KEY))->toBeTrue();
    });

    it('anchors the pattern at both ends', function () {
        // The pattern is anchored here rather than expected to arrive anchored,
        // so a consumer writing a bare '[0-9a-zA-Z]{32}' — which is what the
        // route constraint wants — cannot accidentally accept a substring match.
        expect(AnnounceRouting::matches('junk'.DEFAULT_KEY))->toBeFalse();
        expect(AnnounceRouting::matches(DEFAULT_KEY.'junk'))->toBeFalse();
    });

    it('rejects a trailing newline', function () {
        // PHP's $ matches before a final newline unless /D is used, so without
        // it a key with one appended passes validation and then fails to resolve
        // — which looks like an unknown key rather than a malformed one.
        expect(AnnounceRouting::matches(DEFAULT_KEY."\n"))->toBeFalse();
    });

    it('rejects the empty string', function () {
        expect(AnnounceRouting::matches(''))->toBeFalse();
    });

    it('honours an alternation in the pattern', function () {
        // The pattern is wrapped in a non-capturing group before anchoring, so
        // 'a|b' cannot bind looser than intended — without the group, '^a|b$'
        // means "starts with a, OR ends with b".
        config()->set('bloodhound.routes.key_pattern', 'aaaa|bbbb');

        expect(AnnounceRouting::matches('aaaa'))->toBeTrue();
        expect(AnnounceRouting::matches('bbbb'))->toBeTrue();
        expect(AnnounceRouting::matches('aaaaXX'))->toBeFalse();
        expect(AnnounceRouting::matches('XXbbbb'))->toBeFalse();
    });
});

describe('failing safe on bad config', function () {
    it('falls back to the default pattern when the pattern is empty', function () {
        // An empty pattern would anchor to '/^(?:)$/' and match only the empty
        // string — not a security hole, but a tracker that rejects every key.
        // The default is the honest answer.
        config()->set('bloodhound.routes.key_pattern', '');

        expect(AnnounceRouting::keyPattern())->toBe('[0-9a-zA-Z]{32}');
    });

    it('treats an unrecognised key_source as path, not as keyless', function () {
        // A typo must not silently produce a tracker that ignores announce keys
        // altogether. Only the exact string 'query' switches modes.
        config()->set('bloodhound.routes.key_source', 'quary');

        expect(AnnounceRouting::keySource())->toBe('path');
        expect(AnnounceRouting::keyIsInQuery())->toBeFalse();
    });

    it('falls back to the default parameter name when empty', function () {
        config()->set('bloodhound.routes.key_parameter', '');

        expect(AnnounceRouting::keyParameter())->toBe('announce_key');
    });

    it('falls back to the default paths when empty', function () {
        // An empty path would register a route on '' — matching nothing useful.
        config()->set('bloodhound.routes.announce_path', '');
        config()->set('bloodhound.routes.scrape_path', '');

        expect(AnnounceRouting::announcePath())->toBe('announce');
        expect(AnnounceRouting::scrapePath())->toBe('scrape');
    });
});

describe('path normalisation', function () {
    it('strips leading and trailing slashes', function () {
        // Laravel adds its own separators, so 'announce/' would register a route
        // that nothing matches.
        config()->set('bloodhound.routes.announce_path', '/announce.php/');

        expect(AnnounceRouting::announcePath())->toBe('announce.php');
    });

    it('leaves an interior slash alone', function () {
        // A nested path is legitimate — some trackers serve /tracker/announce.
        config()->set('bloodhound.routes.announce_path', 'tracker/announce');

        expect(AnnounceRouting::announcePath())->toBe('tracker/announce');
    });
});

describe('reading the key off a request', function () {
    it('returns the route value in path mode and ignores the query string', function () {
        config()->set('bloodhound.routes.key_source', 'path');

        $request = Request::create('/announce/'.DEFAULT_KEY.'?announce_key=other');

        expect(AnnounceRouting::keyFromRequest($request, DEFAULT_KEY))->toBe(DEFAULT_KEY);
    });

    it('returns the query value in query mode', function () {
        config()->set('bloodhound.routes.key_source', 'query');
        config()->set('bloodhound.routes.key_parameter', 'passkey');

        $request = Request::create('/announce.php?passkey='.DEFAULT_KEY);

        expect(AnnounceRouting::keyFromRequest($request, null))->toBe(DEFAULT_KEY);
    });

    it('returns null for an absent query key rather than an empty string', function () {
        // The caller has to be able to tell "no key" from "empty key": announce
        // refuses both, but scrape legitimately allows no key at all.
        config()->set('bloodhound.routes.key_source', 'query');
        config()->set('bloodhound.routes.key_parameter', 'passkey');

        expect(AnnounceRouting::keyFromRequest(Request::create('/announce.php'), null))->toBeNull();
        expect(AnnounceRouting::keyFromRequest(Request::create('/announce.php?passkey='), null))->toBeNull();
    });
});
