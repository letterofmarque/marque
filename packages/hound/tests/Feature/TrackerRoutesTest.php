<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Marque\Threepio\Http\Middleware\BlockBrowsers;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Models\Torrent;

// Helper to make tracker requests (without browser headers that would be blocked)
function trackerGet($test, string $url): TestResponse
{
    return $test->withoutMiddleware(BlockBrowsers::class)
        ->withHeaders(['User-Agent' => 'qBittorrent/4.5.0'])
        ->get($url);
}

describe('Public Tracker Routes', function () {
    describe('Announce Route', function () {
        it('exists at /announce without announce key', function () {
            $response = trackerGet($this, '/announce');

            // Should be 200 with bencoded error (missing params), not 404
            expect($response->getStatusCode())->toBe(200);

            $decoded = Bencode::decode($response->getContent());
            expect($decoded)->toHaveKey('failure reason');
        });

        it('returns error for missing required params', function () {
            $response = trackerGet($this, '/announce');

            $decoded = Bencode::decode($response->getContent());
            expect($decoded['failure reason'])->toContain('Missing parameter');
        });

        it('returns error for unregistered torrent', function () {
            $infoHash = str_repeat('aa', 20);

            $response = trackerGet($this, '/announce?'.http_build_query([
                'info_hash' => $infoHash,
                'peer_id' => str_repeat('x', 20),
                'port' => 51413,
                'uploaded' => 0,
                'downloaded' => 0,
                'left' => 1000,
            ]));

            $decoded = Bencode::decode($response->getContent());
            expect($decoded['failure reason'])->toBe('Torrent not registered');
        });

        it('returns error for invalid port', function () {
            $response = trackerGet($this, '/announce?'.http_build_query([
                'info_hash' => str_repeat('aa', 20),
                'peer_id' => str_repeat('x', 20),
                'port' => 0,
                'uploaded' => 0,
                'downloaded' => 0,
                'left' => 1000,
            ]));

            $decoded = Bencode::decode($response->getContent());
            expect($decoded['failure reason'])->toBe('Invalid port');
        });

        it('returns error for invalid peer_id length', function () {
            $torrent = Torrent::create([
                'name' => 'Test Torrent',
                'info_hash' => str_repeat('bb', 20),
                'size' => 1000000,
                'user_id' => 1,
            ]);

            $response = trackerGet($this, '/announce?'.http_build_query([
                'info_hash' => $torrent->info_hash,
                'peer_id' => 'short',
                'port' => 51413,
                'uploaded' => 0,
                'downloaded' => 0,
                'left' => 1000,
            ]));

            $decoded = Bencode::decode($response->getContent());
            expect($decoded['failure reason'])->toBe('Invalid peer_id length');
        });
    });

    describe('Scrape Route', function () {
        it('exists at /scrape without announce key', function () {
            $response = trackerGet($this, '/scrape');

            expect($response->getStatusCode())->toBe(200);

            $decoded = Bencode::decode($response->getContent());
            // Either has files or failure reason
            expect(isset($decoded['files']) || isset($decoded['failure reason']))->toBeTrue();
        });

        it('returns scrape data for known torrent', function () {
            $torrent = Torrent::create([
                'name' => 'Test Torrent',
                'info_hash' => str_repeat('cc', 20),
                'size' => 1000000,
                'user_id' => 1,
            ]);

            $response = trackerGet($this, '/scrape?info_hash='.$torrent->info_hash);

            $decoded = Bencode::decode($response->getContent());
            expect($decoded)->toHaveKey('files');
            // Scrape response uses binary info_hash as key (per BT protocol)
            $binaryHash = pack('H*', $torrent->info_hash);
            expect($decoded['files'][$binaryHash])->toHaveKeys(['complete', 'downloaded', 'incomplete']);
            expect($decoded['files'][$binaryHash]['downloaded'])->toBe(0);
        });
    });
});
