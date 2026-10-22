<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;

// Helper to make tracker requests (without browser headers that would be blocked)
function trackerGet($test, string $url): TestResponse
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get($url);
}

describe('Tracker Routes', function () {
    describe('Announce Route', function () {
        it('exists and responds to valid announce key format', function () {
            // Create user with announce key
            $user = TestUser::create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
            ]);
            issueAnnounceKey($user, 'aaaabbbbccccddddeeeeffffgggghhhh');

            // Request without required params should get bencoded error
            $response = trackerGet($this, '/announce/'.keyOf($user));

            // Should be 200 with bencoded response (not 404)
            expect($response->getStatusCode())->toBe(200);

            $decoded = Bencode::decode($response->getContent());
            expect($decoded)->toHaveKey('failure reason');
        });

        // #11070, the default from 9.0: a malformed key gets the reason, not
        // the app's HTML 404, which a client shows as a garbled error.
        it('answers a malformed announce key with a bencoded failure, not a 404', function () {
            foreach (['/announce/invalid', '/announce/'.str_repeat('a', 40), '/announce/a/b', '/announce'] as $path) {
                $response = trackerGet($this, $path);

                expect($response->getStatusCode())->toBe(200, $path)
                    ->and(Bencode::decode($response->getContent())['failure reason'])->toBe('Invalid announce key');
            }
        });

        it('rejects unknown announce key', function () {
            $response = trackerGet($this, '/announce/aaaabbbbccccddddeeeeffffgggghhhh');

            $decoded = Bencode::decode($response->getContent());
            expect($decoded['failure reason'])->toBe('Unknown announce key');
        });
    });

    describe('Scrape Route', function () {
        it('exists and responds', function () {
            // Scrape without announce key should still work (public scrape)
            // But needs info_hash
            $response = trackerGet($this, '/scrape');

            // Should be 200 with bencoded response
            expect($response->getStatusCode())->toBe(200);

            $decoded = Bencode::decode($response->getContent());
            // Either has files or failure reason
            expect(isset($decoded['files']) || isset($decoded['failure reason']))->toBeTrue();
        });

        it('accepts announce key parameter', function () {
            $user = TestUser::create([
                'name' => 'Test User',
                'email' => 'scrape@example.com',
                'password' => 'password',
            ]);
            issueAnnounceKey($user, 'zzzzyyyyxxxxwwwwvvvvuuuuttttssss');

            $response = trackerGet($this, '/scrape/'.keyOf($user));

            expect($response->getStatusCode())->toBe(200);
        });

        it('answers a malformed scrape key with a bencoded failure, not a 404', function () {
            $response = trackerGet($this, '/scrape/invalid');

            expect($response->getStatusCode())->toBe(200)
                ->and(Bencode::decode($response->getContent())['failure reason'])->toBe('Invalid announce key');
        });
    });
});
