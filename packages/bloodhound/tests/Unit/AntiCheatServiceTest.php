<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Services\AntiCheatService;
use Marque\Bloodhound\Services\PeerService;

beforeEach(function () {
    // Clear Redis test keys
    $keys = Redis::keys('bloodhound_test:*');
    if (! empty($keys)) {
        Redis::del($keys);
    }

    config()->set('bloodhound.anti_cheat.enabled', true);
    config()->set('bloodhound.redis.prefix', 'bloodhound_test:');
});

describe('AntiCheatService', function () {
    describe('port checking', function () {
        it('allows valid ports', function () {
            config()->set('bloodhound.blacklisted_ports', []);

            $service = app(AntiCheatService::class);

            $result = $service->checkPort(51413);

            expect($result['passed'])->toBeTrue();
        });

        it('rejects blacklisted ports', function () {
            config()->set('bloodhound.blacklisted_ports', [6881, 6882, 6883]);

            $service = app(AntiCheatService::class);

            $result = $service->checkPort(6881);

            expect($result['passed'])->toBeFalse();
            expect($result['reason'])->toContain('blacklisted');
        });

        it('rejects invalid port numbers', function () {
            $service = app(AntiCheatService::class);

            expect($service->checkPort(0)['passed'])->toBeFalse();
            expect($service->checkPort(-1)['passed'])->toBeFalse();
            expect($service->checkPort(65536)['passed'])->toBeFalse();
        });

        it('accepts edge case valid ports', function () {
            config()->set('bloodhound.blacklisted_ports', []);

            $service = app(AntiCheatService::class);

            expect($service->checkPort(1)['passed'])->toBeTrue();
            expect($service->checkPort(65535)['passed'])->toBeTrue();
        });
    });

    describe('announce frequency', function () {
        it('allows first announce', function () {
            config()->set('bloodhound.anti_cheat.min_announce_gap', 60);

            $service = app(AntiCheatService::class);

            $result = $service->checkAnnounceFrequency(1, '-qB4500-xxxxxxxxxxxx');

            expect($result['passed'])->toBeTrue();
        });

        it('rejects rapid announces', function () {
            config()->set('bloodhound.anti_cheat.min_announce_gap', 60);

            $service = app(AntiCheatService::class);

            // First announce
            $service->checkAnnounceFrequency(1, '-qB4500-xxxxxxxxxxxx');

            // Immediate second announce
            $result = $service->checkAnnounceFrequency(1, '-qB4500-xxxxxxxxxxxx');

            expect($result['passed'])->toBeFalse();
            expect($result['reason'])->toContain('too frequently');
        });

        it('allows announces after gap', function () {
            config()->set('bloodhound.anti_cheat.min_announce_gap', 1);

            $service = app(AntiCheatService::class);

            // First announce
            $service->checkAnnounceFrequency(1, '-qB4500-xxxxxxxxxxxx');

            // Wait for gap
            sleep(2);

            // Second announce
            $result = $service->checkAnnounceFrequency(1, '-qB4500-xxxxxxxxxxxx');

            expect($result['passed'])->toBeTrue();
        })->skip(fn () => getenv('CI'), 'Skipped in CI due to sleep');
    });

    describe('connection limits', function () {
        it('allows connections within limits', function () {
            config()->set('bloodhound.anti_cheat.max_connections_per_torrent', 3);
            config()->set('bloodhound.anti_cheat.max_connections_per_ip', 10);

            $service = app(AntiCheatService::class);

            $result = $service->checkConnectionLimits(1, 1, '192.168.1.1');

            expect($result['passed'])->toBeTrue();
        });

        it('rejects when user exceeds per-torrent limit', function () {
            config()->set('bloodhound.anti_cheat.max_connections_per_torrent', 2);
            config()->set('bloodhound.anti_cheat.max_connections_per_ip', 10);

            $peerService = app(PeerService::class);

            // Add 2 peers for user 1 on torrent 1
            $peerService->upsertPeer(1, '-qB4500-peer1xxxxxxx', 1, '192.168.1.1', 51413, 0, 0, 1000, 'qB', false);
            $peerService->upsertPeer(1, '-qB4500-peer2xxxxxxx', 1, '192.168.1.2', 51414, 0, 0, 1000, 'qB', false);

            $service = app(AntiCheatService::class);

            $result = $service->checkConnectionLimits(1, 1, '192.168.1.3');

            expect($result['passed'])->toBeFalse();
            expect($result['reason'])->toContain('per torrent');
        });

        it('rejects when IP exceeds limit', function () {
            config()->set('bloodhound.anti_cheat.max_connections_per_torrent', 10);
            config()->set('bloodhound.anti_cheat.max_connections_per_ip', 2);

            $peerService = app(PeerService::class);

            // Add 2 peers from same IP
            $peerService->upsertPeer(1, '-qB4500-peer1xxxxxxx', 1, '192.168.1.1', 51413, 0, 0, 1000, 'qB', false);
            $peerService->upsertPeer(2, '-qB4500-peer2xxxxxxx', 2, '192.168.1.1', 51414, 0, 0, 1000, 'qB', false);

            $service = app(AntiCheatService::class);

            $result = $service->checkConnectionLimits(3, 3, '192.168.1.1');

            expect($result['passed'])->toBeFalse();
            expect($result['reason'])->toContain('per IP');
        });
    });

    describe('speed sanity', function () {
        it('allows reasonable speeds', function () {
            config()->set('bloodhound.anti_cheat.max_upload_speed', 100 * 1024 * 1024);
            config()->set('bloodhound.anti_cheat.max_download_speed', 100 * 1024 * 1024);

            $peerService = app(PeerService::class);

            // Add peer with initial stats
            $peerService->upsertPeer(1, '-qB4500-xxxxxxxxxxxx', 1, '192.168.1.1', 51413, 0, 0, 1000000, 'qB', false);

            $service = app(AntiCheatService::class);

            // 10 MB in reasonable time - this checks existing peer
            $result = $service->checkSpeedSanity(1, '-qB4500-xxxxxxxxxxxx', 10 * 1024 * 1024, 10 * 1024 * 1024);

            expect($result['passed'])->toBeTrue();
        });

        it('allows first announce without existing peer', function () {
            $service = app(AntiCheatService::class);

            // No existing peer - should pass
            $result = $service->checkSpeedSanity(1, '-qB4500-new_peer_xxx', 1000000, 1000000);

            expect($result['passed'])->toBeTrue();
        });
    });

    describe('data sanity', function () {
        it('allows valid data', function () {
            config()->set('bloodhound.anti_cheat.check_data_sanity', true);

            $service = app(AntiCheatService::class);

            // downloaded + left = torrent size
            $result = $service->checkDataSanity(500000, 500000, 1000000);

            expect($result['passed'])->toBeTrue();
        });

        it('allows completed download', function () {
            config()->set('bloodhound.anti_cheat.check_data_sanity', true);

            $service = app(AntiCheatService::class);

            // Fully downloaded
            $result = $service->checkDataSanity(1000000, 0, 1000000);

            expect($result['passed'])->toBeTrue();
        });

        it('rejects data inconsistency', function () {
            config()->set('bloodhound.anti_cheat.check_data_sanity', true);

            $service = app(AntiCheatService::class);

            // downloaded + left != torrent size
            $result = $service->checkDataSanity(100000, 100000, 1000000);

            expect($result['passed'])->toBeFalse();
            expect($result['reason'])->toContain('inconsistency');
        });

        it('rejects download exceeding torrent size', function () {
            config()->set('bloodhound.anti_cheat.check_data_sanity', true);

            $service = app(AntiCheatService::class);

            // downloaded > torrent size
            $result = $service->checkDataSanity(2000000, 0, 1000000);

            expect($result['passed'])->toBeFalse();
            expect($result['reason'])->toContain('exceeds');
        });

        it('skips check when disabled', function () {
            config()->set('bloodhound.anti_cheat.check_data_sanity', false);

            $service = app(AntiCheatService::class);

            // Invalid data should pass when check is disabled
            $result = $service->checkDataSanity(100000, 100000, 1000000);

            expect($result['passed'])->toBeTrue();
        });
    });

    describe('full check', function () {
        it('passes when all checks pass', function () {
            config()->set('bloodhound.blacklisted_ports', []);
            config()->set('bloodhound.anti_cheat.check_data_sanity', true);

            $service = app(AntiCheatService::class);

            $result = $service->check(
                torrentId: 1,
                userId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 500000,
                left: 500000,
                torrentSize: 1000000,
            );

            expect($result['passed'])->toBeTrue();
        });

        it('fails on first failing check', function () {
            config()->set('bloodhound.blacklisted_ports', [51413]);

            $service = app(AntiCheatService::class);

            $result = $service->check(
                torrentId: 1,
                userId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                torrentSize: 1000000,
            );

            expect($result['passed'])->toBeFalse();
            expect($result['reason'])->toContain('blacklisted');
        });

        it('skips all checks when disabled', function () {
            config()->set('bloodhound.anti_cheat.enabled', false);
            config()->set('bloodhound.blacklisted_ports', [51413]);

            $service = app(AntiCheatService::class);

            $result = $service->check(
                torrentId: 1,
                userId: 1,
                peerId: '-qB4500-xxxxxxxxxxxx',
                ip: '192.168.1.1',
                port: 51413,
                uploaded: 0,
                downloaded: 0,
                left: 1000000,
                torrentSize: 1000000,
            );

            expect($result['passed'])->toBeTrue();
        });
    });
});
