<?php

declare(strict_types=1);

use Marque\Bloodhound\Services\AntiCheatService;

beforeEach(function () {
    config()->set('bloodhound.anti_cheat.enabled', true);
    config()->set('threepio.redis.prefix', 'marque_test:');
    config()->set('threepio.blacklisted_ports', []);
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
            config()->set('threepio.blacklisted_ports', [6881, 6882, 6883]);

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
});
