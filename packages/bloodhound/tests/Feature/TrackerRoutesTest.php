<?php

declare(strict_types=1);

use Marque\Bloodhound\Tests\TestUser;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Models\Torrent;

// Helper to make tracker requests (without browser headers that would be blocked)
function trackerGet($test, string $url): \Illuminate\Testing\TestResponse
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get($url);
}

describe('Tracker Routes', function () {
    describe('Announce Route', function () {
        it('exists and responds to valid passkey format', function () {
            // Create user with passkey
            $user = TestUser::create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'passkey' => 'aaaabbbbccccddddeeeeffffgggghhhh', // 32 char alphanumeric
            ]);

            // Request without required params should get bencoded error
            $response = trackerGet($this, '/announce/'.$user->passkey);

            // Should be 200 with bencoded response (not 404)
            expect($response->getStatusCode())->toBe(200);

            $decoded = Bencode::decode($response->getContent());
            expect($decoded)->toHaveKey('failure reason');
        });

        it('returns 404 for invalid passkey format', function () {
            // Invalid passkey (too short)
            $response = trackerGet($this, '/announce/invalid');

            expect($response->getStatusCode())->toBe(404);
        });

        it('rejects unknown passkey', function () {
            $response = trackerGet($this, '/announce/aaaabbbbccccddddeeeeffffgggghhhh');

            $decoded = Bencode::decode($response->getContent());
            expect($decoded['failure reason'])->toBe('Unknown passkey');
        });
    });

    describe('Scrape Route', function () {
        it('exists and responds', function () {
            // Scrape without passkey should still work (public scrape)
            // But needs info_hash
            $response = trackerGet($this, '/scrape');

            // Should be 200 with bencoded response
            expect($response->getStatusCode())->toBe(200);

            $decoded = Bencode::decode($response->getContent());
            // Either has files or failure reason
            expect(isset($decoded['files']) || isset($decoded['failure reason']))->toBeTrue();
        });

        it('accepts passkey parameter', function () {
            $user = TestUser::create([
                'name' => 'Test User',
                'email' => 'scrape@example.com',
                'password' => 'password',
                'passkey' => 'zzzzyyyyxxxxwwwwvvvvuuuuttttssss',
            ]);

            $response = trackerGet($this, '/scrape/'.$user->passkey);

            expect($response->getStatusCode())->toBe(200);
        });

        it('returns 404 for invalid passkey format', function () {
            $response = trackerGet($this, '/scrape/invalid');

            expect($response->getStatusCode())->toBe(404);
        });
    });
});
