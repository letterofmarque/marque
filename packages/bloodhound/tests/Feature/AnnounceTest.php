<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Support\Bencode;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    // Clear Redis test keys
    $keys = Redis::keys('bloodhound_test:*');
    if (! empty($keys)) {
        Redis::del($keys);
    }

    // Disable client validation for most tests
    config()->set('bloodhound.client_validation.enabled', false);
    config()->set('bloodhound.anti_cheat.enabled', false);
});

describe('Announce Controller', function () {
    it('rejects invalid passkey format', function () {
        $response = $this->get('/announce/invalid');

        expect($response->getStatusCode())->toBe(200);

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toBe('Invalid passkey');
    });

    it('rejects unknown passkey', function () {
        $response = $this->get('/announce/'.str_repeat('a', 32).'?info_hash='.str_repeat('0', 40).'&peer_id=-qB4500-xxxxxxxxxxxx&port=51413&uploaded=0&downloaded=0&left=1000');

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toBe('Unknown passkey');
    });

    it('rejects missing required parameters', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('a', 32),
        ]);

        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.str_repeat('0', 40));

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toContain('Missing parameter');
    });

    it('rejects unregistered torrent', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('b', 32),
        ]);

        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.str_repeat('0', 40).'&peer_id=-qB4500-xxxxxxxxxxxx&port=51413&uploaded=0&downloaded=0&left=1000');

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toBe('Torrent not registered');
    });

    it('accepts valid announce request', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('c', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('a', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id=-qB4500-xxxxxxxxxxxx&port=51413&uploaded=0&downloaded=0&left=1000000&event=started');

        $decoded = Bencode::decode($response->getContent());

        expect($decoded)->toHaveKey('interval');
        expect($decoded)->toHaveKey('complete');
        expect($decoded)->toHaveKey('incomplete');
        expect($decoded)->toHaveKey('peers');
        expect($decoded['interval'])->toBe(1800);
    });

    it('tracks leecher on started event', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('d', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('b', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        // First announce - should be a leecher (left > 0)
        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id=-qB4500-xxxxxxxxxxxx&port=51413&uploaded=0&downloaded=0&left=1000000&event=started');

        $decoded = Bencode::decode($response->getContent());

        expect($decoded['incomplete'])->toBe(1);
        expect($decoded['complete'])->toBe(0);
    });

    it('tracks seeder when left is zero', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('e', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('c', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        // Announce with left=0 (seeder)
        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id=-qB4500-xxxxxxxxxxxx&port=51413&uploaded=1000000&downloaded=0&left=0&event=started');

        $decoded = Bencode::decode($response->getContent());

        expect($decoded['complete'])->toBe(1);
        expect($decoded['incomplete'])->toBe(0);
    });

    it('removes peer on stopped event', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('f', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('d', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        $peerId = '-qB4500-xxxxxxxxxxxx';

        // Start
        $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id='.$peerId.'&port=51413&uploaded=0&downloaded=0&left=1000000&event=started');

        // Stop
        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id='.$peerId.'&port=51413&uploaded=0&downloaded=500000&left=500000&event=stopped');

        $decoded = Bencode::decode($response->getContent());

        expect($decoded['incomplete'])->toBe(0);
        expect($decoded['complete'])->toBe(0);
    });

    it('returns peers in compact format by default', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('g', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('e', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id=-qB4500-xxxxxxxxxxxx&port=51413&uploaded=0&downloaded=0&left=1000000&compact=1');

        $decoded = Bencode::decode($response->getContent());

        // Peers should be a string (compact format)
        expect($decoded['peers'])->toBeString();
    });

    it('accepts binary info_hash', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('h', 32),
        ]);

        $hexHash = str_repeat('f', 40);
        $torrent = Torrent::create([
            'info_hash' => $hexHash,
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        // Send binary info_hash (URL encoded)
        $binaryHash = pack('H*', $hexHash);
        $encodedHash = urlencode($binaryHash);

        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$encodedHash.'&peer_id=-qB4500-xxxxxxxxxxxx&port=51413&uploaded=0&downloaded=0&left=1000000');

        $decoded = Bencode::decode($response->getContent());

        expect($decoded)->toHaveKey('interval');
    });

    it('rejects invalid port', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('i', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('1', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id=-qB4500-xxxxxxxxxxxx&port=0&uploaded=0&downloaded=0&left=1000000');

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toBe('Invalid port');
    });

    it('rejects invalid peer_id length', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('j', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('2', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id=short&port=51413&uploaded=0&downloaded=0&left=1000000');

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toBe('Invalid peer_id length');
    });
});

describe('Announce with client validation', function () {
    beforeEach(function () {
        config()->set('bloodhound.client_validation.enabled', true);
        config()->set('bloodhound.client_validation.mode', 'whitelist');
        config()->set('bloodhound.whitelist', [
            'qBittorrent' => [
                'pattern' => '/^-qB(\d)(\d)(\d{2})-/',
                'min_version' => '4.3.0',
            ],
        ]);
    });

    it('rejects non-whitelisted client', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('k', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('3', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        // Unknown client
        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id=-XX1234-xxxxxxxxxxxx&port=51413&uploaded=0&downloaded=0&left=1000000');

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toContain('not allowed');
    });

    it('accepts whitelisted client', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('l', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('4', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        // qBittorrent 4.5.0
        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id=-qB4500-xxxxxxxxxxxx&port=51413&uploaded=0&downloaded=0&left=1000000');

        $decoded = Bencode::decode($response->getContent());
        expect($decoded)->toHaveKey('interval');
    });
});

describe('Announce with anti-cheat', function () {
    beforeEach(function () {
        config()->set('bloodhound.anti_cheat.enabled', true);
        config()->set('bloodhound.blacklisted_ports', [6881, 6882]);
    });

    it('rejects blacklisted port', function () {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('m', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('5', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id=-qB4500-xxxxxxxxxxxx&port=6881&uploaded=0&downloaded=0&left=1000000');

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toContain('blacklisted');
    });

    it('rejects data inconsistency', function () {
        config()->set('bloodhound.anti_cheat.check_data_sanity', true);

        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'passkey' => str_repeat('n', 32),
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('6', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $user->id,
        ]);

        // left + downloaded != torrent size
        $response = $this->get('/announce/'.$user->passkey.'?info_hash='.$torrent->info_hash.'&peer_id=-qB4500-xxxxxxxxxxxx&port=51413&uploaded=0&downloaded=100000&left=100000');

        $decoded = Bencode::decode($response->getContent());
        expect($decoded['failure reason'])->toContain('inconsistency');
    });
});
