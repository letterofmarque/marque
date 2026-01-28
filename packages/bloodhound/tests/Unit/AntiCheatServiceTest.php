<?php

declare(strict_types=1);

use Marque\Bloodhound\Services\AntiCheatService;
use Marque\Bloodhound\Services\PeerService;

beforeEach(function () {
    config()->set('bloodhound.anti_cheat.enabled', true);
    config()->set('bloodhound.redis.prefix', 'bloodhound_test:');
    config()->set('bloodhound.blacklisted_ports', []);
});

describe('AntiCheatService', function () {
    describe('port checking', function () {
        it('allows valid ports', function () {
            // Use app() to get a real service instance (requires Redis to be configured but not connected)
            // We'll test the port check method which doesn't need Redis
            $service = $this->app->make(AntiCheatService::class);

            $result = $service->checkPort(51413);

            expect($result['passed'])->toBeTrue();
        });

        it('rejects blacklisted ports', function () {
            config()->set('bloodhound.blacklisted_ports', [6881, 6882, 6883]);

            $service = $this->app->make(AntiCheatService::class);

            $result = $service->checkPort(6881);

            expect($result['passed'])->toBeFalse();
            expect($result['reason'])->toContain('blacklisted');
        });

        it('rejects invalid port numbers', function () {
            $service = $this->app->make(AntiCheatService::class);

            expect($service->checkPort(0)['passed'])->toBeFalse();
            expect($service->checkPort(-1)['passed'])->toBeFalse();
            expect($service->checkPort(65536)['passed'])->toBeFalse();
        });

        it('accepts edge case valid ports', function () {
            $service = $this->app->make(AntiCheatService::class);

            expect($service->checkPort(1)['passed'])->toBeTrue();
            expect($service->checkPort(65535)['passed'])->toBeTrue();
        });
    });

    describe('data sanity', function () {
        it('allows valid data', function () {
            $service = $this->app->make(AntiCheatService::class);

            // uploaded, downloaded, left, torrentSize
            // downloaded + left should roughly equal torrentSize
            $result = $service->checkDataSanity(500000, 500000, 500000, 1000000);

            expect($result['passed'])->toBeTrue();
        });

        it('allows completed download', function () {
            $service = $this->app->make(AntiCheatService::class);

            // Fully downloaded (left = 0)
            $result = $service->checkDataSanity(1000000, 1000000, 0, 1000000);

            expect($result['passed'])->toBeTrue();
        });

        it('rejects data inconsistency', function () {
            $service = $this->app->make(AntiCheatService::class);

            // downloaded + left != torrent size (way off)
            $result = $service->checkDataSanity(0, 100000, 100000, 1000000);

            expect($result['passed'])->toBeFalse();
            expect($result['reason'])->toContain('inconsistency');
        });

        it('rejects download exceeding torrent size', function () {
            $service = $this->app->make(AntiCheatService::class);

            // downloaded > torrent size * 1.1
            $result = $service->checkDataSanity(0, 2000000, 0, 1000000);

            expect($result['passed'])->toBeFalse();
            expect($result['reason'])->toContain('more than torrent size');
        });
    });
});
