<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Services\PeerService;

beforeEach(function () {
    // Clear Redis test keys
    $keys = Redis::keys('bloodhound_test:*');
    if (! empty($keys)) {
        Redis::del($keys);
    }
});

describe('PeerService', function () {
    describe('upsertPeer', function () {
        it('adds new peer', function () {
            $service = app(PeerService::class);

            $result = $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            expect($result['was_existing'])->toBeFalse();
            expect($result['upload_delta'])->toBe(0);
            expect($result['download_delta'])->toBe(0);
        });

        it('updates existing peer', function () {
            $service = app(PeerService::class);

            // Add peer
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            // Update peer with new stats
            $result = $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 100000,
                downloaded: 500000,
                left: 500000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            expect($result['was_existing'])->toBeTrue();
            expect($result['upload_delta'])->toBe(100000);
            expect($result['download_delta'])->toBe(500000);
        });

        it('tracks leecher count', function () {
            $service = app(PeerService::class);

            // Add leecher
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            expect($service->getLeechers(1))->toBe(1);
            expect($service->getSeeders(1))->toBe(0);
        });

        it('tracks seeder count', function () {
            $service = app(PeerService::class);

            // Add seeder
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 1000000,
                downloaded: 0,
                left: 0,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: true,
            );

            expect($service->getSeeders(1))->toBe(1);
            expect($service->getLeechers(1))->toBe(0);
        });

        it('updates counts when peer becomes seeder', function () {
            $service = app(PeerService::class);

            // Add as leecher
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            expect($service->getLeechers(1))->toBe(1);
            expect($service->getSeeders(1))->toBe(0);

            // Update to seeder
            $result = $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 1000000,
                left: 0,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: true,
            );

            expect($result['status_changed'])->toBeTrue();
            expect($service->getLeechers(1))->toBe(0);
            expect($service->getSeeders(1))->toBe(1);
        });
    });

    describe('removePeer', function () {
        it('removes existing peer', function () {
            $service = app(PeerService::class);

            // Add peer
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            expect($service->getLeechers(1))->toBe(1);

            // Remove peer
            $removed = $service->removePeer(1, '-qB4500-xxxxxxxxxxxx');

            expect($removed)->not->toBeNull();
            expect($service->getLeechers(1))->toBe(0);
        });

        it('returns null for non-existent peer', function () {
            $service = app(PeerService::class);

            $removed = $service->removePeer(1, '-qB4500-xxxxxxxxxxxx');

            expect($removed)->toBeNull();
        });
    });

    describe('getPeer', function () {
        it('returns peer data', function () {
            $service = app(PeerService::class);

            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 100,
                downloaded: 200,
                left: 800,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            $peer = $service->getPeer(1, '-qB4500-xxxxxxxxxxxx');

            expect($peer)->not->toBeNull();
            expect($peer['ip'])->toBe('192.168.1.1');
            expect($peer['port'])->toBe(51413);
            expect($peer['uploaded'])->toBe(100);
            expect($peer['downloaded'])->toBe(200);
            expect($peer['left'])->toBe(800);
            expect($peer['is_seeder'])->toBeFalse();
        });

        it('returns null for non-existent peer', function () {
            $service = app(PeerService::class);

            $peer = $service->getPeer(1, '-qB4500-xxxxxxxxxxxx');

            expect($peer)->toBeNull();
        });
    });

    describe('getPeersForAnnounce', function () {
        it('returns peers excluding requester', function () {
            $service = app(PeerService::class);

            // Add multiple peers
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-peer1xxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-peer2xxxxxxx',
                userId: 2,
                ip: '192.168.1.2',
                port: 51414,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            // Get peers excluding peer1
            $peers = $service->getPeersForAnnounce(
                torrentId: 1,
                excludePeerId: '-qB4500-peer1xxxxxxx',
                isSeeder: false,
                limit: 50,
            );

            expect(count($peers))->toBe(1);
            expect($peers[0]['ip'])->toBe('192.168.1.2');
        });

        it('returns only leechers when requester is seeder', function () {
            $service = app(PeerService::class);

            // Add seeder
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-seeder111111',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 1000000,
                downloaded: 0,
                left: 0,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: true,
            );

            // Add leecher
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-leecher11111',
                userId: 2,
                ip: '192.168.1.2',
                port: 51414,
                uploaded: 0,
                downloaded: 500000,
                left: 500000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            // Requester is seeder - should only get leechers
            $peers = $service->getPeersForAnnounce(
                torrentId: 1,
                excludePeerId: '-qB4500-requester1111',
                isSeeder: true,
                limit: 50,
            );

            expect(count($peers))->toBe(1);
            expect($peers[0]['ip'])->toBe('192.168.1.2');
        });

        it('respects limit', function () {
            $service = app(PeerService::class);

            // Add many peers
            for ($i = 1; $i <= 10; $i++) {
                $service->upsertPeer(
                    torrentId: 1,
                    peerId: "-qB4500-peer{$i}xxxxxxx",
                    userId: $i,
                    ip: "192.168.1.{$i}",
                    port: 51413 + $i,
                    uploaded: 0,
                    downloaded: 0,
                    left: 1000000,
                    userAgent: 'qBittorrent/4.5.0',
                    isSeeder: false,
                );
            }

            $peers = $service->getPeersForAnnounce(
                torrentId: 1,
                excludePeerId: '-qB4500-requesterxxxx',
                isSeeder: false,
                limit: 5,
            );

            expect(count($peers))->toBe(5);
        });
    });

    describe('swarm stats', function () {
        it('tracks total uploaded in swarm', function () {
            $service = app(PeerService::class);

            // Add peer
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            // Update with upload delta
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 500000,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            $stats = $service->getSwarmStats(1);

            expect($stats['total_uploaded'])->toBe(500000);
        });

        it('tracks total downloaded in swarm', function () {
            $service = app(PeerService::class);

            // Add peer
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            // Update with download delta
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 300000,
                left: 700000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            $stats = $service->getSwarmStats(1);

            expect($stats['total_downloaded'])->toBe(300000);
        });
    });

    describe('connection tracking', function () {
        it('tracks user peer count per torrent', function () {
            $service = app(PeerService::class);

            // Add multiple peers for same user on same torrent
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-peer1xxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-peer2xxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51414,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            $count = $service->getUserPeerCountForTorrent(1, 1);

            expect($count)->toBe(2);
        });

        it('tracks IP peer count', function () {
            $service = app(PeerService::class);

            // Add peers from same IP
            $service->upsertPeer(
                torrentId: 1,
                peerId: '-qB4500-peer1xxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            $service->upsertPeer(
                torrentId: 2,
                peerId: '-qB4500-peer2xxxxxxx',
                userId: 1,
                ip: '192.168.1.1',
                port: 51414,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                userAgent: 'qBittorrent/4.5.0',
                isSeeder: false,
            );

            $count = $service->getIpPeerCount('192.168.1.1');

            expect($count)->toBe(2);
        });
    });
});
