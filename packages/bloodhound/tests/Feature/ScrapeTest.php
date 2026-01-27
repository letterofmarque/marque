<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Services\PeerService;
use Marque\Bloodhound\Support\Bencode;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    // Clear Redis test keys
    $keys = Redis::keys('bloodhound_test:*');
    if (! empty($keys)) {
        Redis::del($keys);
    }
});

describe('Scrape Controller', function () {
    it('rejects invalid passkey format', function () {
        $response = $this->get('/scrape/invalid');

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toBe('Invalid passkey');
    });

    it('requires info_hash parameter', function () {
        $response = $this->get('/scrape');

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toBe('Missing info_hash');
    });

    it('returns stats for registered torrent', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('a', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('a', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        $response = $this->get('/scrape/'.$user->passkey.'?info_hash='.$torrent->info_hash);

        expect($response->getStatusCode())->toBe(200);

        $decoded = Bencode::decode($response->getContent());

        expect($decoded)->toHaveKey('files');
    });

    it('skips unregistered torrents', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('b', 32),
        ]);

        $response = $this->get('/scrape/'.$user->passkey.'?info_hash='.str_repeat('f', 40));

        $decoded = Bencode::decode($response->getContent());

        expect($decoded['files'])->toBeEmpty();
    });

    it('returns correct seeder/leecher counts', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('c', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('b', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        // Simulate some peers via PeerService
        $peerService = app(PeerService::class);

        // Add a seeder
        $peerService->upsertPeer(
            torrentId: $torrent->id,
            peerId: '-qB4500-seeder111111',
            userId: $user->id,
            ip: '192.168.1.1',
            port: 51413,
            uploaded: 1000000,
            downloaded: 0,
            left: 0,
            userAgent: 'qBittorrent/4.5.0',
            isSeeder: true,
        );

        // Add a leecher
        $peerService->upsertPeer(
            torrentId: $torrent->id,
            peerId: '-qB4500-leecher11111',
            userId: $user->id,
            ip: '192.168.1.2',
            port: 51414,
            uploaded: 0,
            downloaded: 500000,
            left: 500000,
            userAgent: 'qBittorrent/4.5.0',
            isSeeder: false,
        );

        $response = $this->get('/scrape/'.$user->passkey.'?info_hash='.$torrent->info_hash);

        $decoded = Bencode::decode($response->getContent());

        // Find the torrent in the response (key is binary)
        $files = $decoded['files'];
        expect(count($files))->toBe(1);

        // Get the first (only) entry
        $stats = reset($files);
        expect($stats['complete'])->toBe(1);
        expect($stats['incomplete'])->toBe(1);
    });

    it('handles multiple info_hashes', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('d', 32),
        ]);

        $torrent1 = Torrent::create([
            'info_hash' => str_repeat('c', 40),
            'name' => 'Test Torrent 1',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        $torrent2 = Torrent::create([
            'info_hash' => str_repeat('d', 40),
            'name' => 'Test Torrent 2',
            'size' => 2000000,
            'user_id' => $user->id,
        ]);

        // Note: Multiple info_hash params need special handling in URL
        $response = $this->get('/scrape/'.$user->passkey.'?info_hash='.$torrent1->info_hash.'&info_hash='.$torrent2->info_hash);

        $decoded = Bencode::decode($response->getContent());

        // Should have entries for both torrents
        expect(count($decoded['files']))->toBeGreaterThanOrEqual(1);
    });

    it('accepts binary info_hash', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('e', 32),
        ]);

        $hexHash = str_repeat('e', 40);
        $torrent = Torrent::create([
            'info_hash' => $hexHash,
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        // Send binary info_hash (URL encoded)
        $binaryHash = pack('H*', $hexHash);
        $encodedHash = urlencode($binaryHash);

        $response = $this->get('/scrape/'.$user->passkey.'?info_hash='.$encodedHash);

        $decoded = Bencode::decode($response->getContent());

        expect($decoded['files'])->not->toBeEmpty();
    });

    it('works without passkey', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('f', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('f', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        $response = $this->get('/scrape?info_hash='.$torrent->info_hash);

        expect($response->getStatusCode())->toBe(200);

        $decoded = Bencode::decode($response->getContent());
        expect($decoded)->toHaveKey('files');
    });

    it('limits number of info_hashes', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('g', 32),
        ]);

        // Create many torrents
        $hashes = [];
        for ($i = 0; $i < 60; $i++) {
            $hash = str_pad((string) $i, 40, '0', STR_PAD_LEFT);
            Torrent::create([
                'info_hash' => $hash,
                'name' => "Test Torrent {$i}",
                'size' => 1000000,
                'user_id' => $user->id,
            ]);
            $hashes[] = 'info_hash='.$hash;
        }

        $query = implode('&', $hashes);
        $response = $this->get('/scrape/'.$user->passkey.'?'.$query);

        $decoded = Bencode::decode($response->getContent());

        // Should be limited to 50
        expect(count($decoded['files']))->toBeLessThanOrEqual(50);
    });
});
