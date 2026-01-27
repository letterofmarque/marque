<?php

declare(strict_types=1);

use Marque\Bloodhound\Services\ClientValidationService;

beforeEach(function () {
    config()->set('bloodhound.client_validation.enabled', true);
    config()->set('bloodhound.client_validation.mode', 'whitelist');
    config()->set('bloodhound.whitelist', [
        'qBittorrent' => [
            'pattern' => '/^-qB(\d)(\d)(\d{2})-/',
            'min_version' => '4.3.0',
        ],
        'Deluge' => [
            'pattern' => '/^-DE(\d)(\d)(\d)(\d)-/',
            'min_version' => '2.0.0',
        ],
        'Transmission' => [
            'pattern' => '/^-TR(\d)(\d)(\d{2})-/',
            'min_version' => '3.0.0',
        ],
    ]);
    config()->set('bloodhound.blacklist', [
        'Xunlei' => [
            'pattern' => '/^-XL/',
        ],
        'Thunder' => [
            'pattern' => '/^-SD/',
        ],
    ]);
});

describe('ClientValidationService', function () {
    describe('whitelist mode', function () {
        it('allows valid qBittorrent client', function () {
            $service = new ClientValidationService();

            // qBittorrent 4.5.0
            $result = $service->validate('-qB4500-xxxxxxxxxxxx');

            expect($result['valid'])->toBeTrue();
            expect($result['client'])->toBe('qBittorrent');
            expect($result['version'])->toBe('4.5.0');
        });

        it('allows valid Deluge client', function () {
            $service = new ClientValidationService();

            // Deluge 2.1.1
            $result = $service->validate('-DE2110-xxxxxxxxxxxx');

            expect($result['valid'])->toBeTrue();
            expect($result['client'])->toBe('Deluge');
            expect($result['version'])->toBe('2.1.1');
        });

        it('allows valid Transmission client', function () {
            $service = new ClientValidationService();

            // Transmission 3.00
            $result = $service->validate('-TR3000-xxxxxxxxxxxx');

            expect($result['valid'])->toBeTrue();
            expect($result['client'])->toBe('Transmission');
            expect($result['version'])->toBe('3.0.0');
        });

        it('rejects client below minimum version', function () {
            $service = new ClientValidationService();

            // qBittorrent 4.2.0 (below 4.3.0 min)
            $result = $service->validate('-qB4200-xxxxxxxxxxxx');

            expect($result['valid'])->toBeFalse();
            expect($result['reason'])->toContain('minimum version');
        });

        it('rejects unknown client in whitelist mode', function () {
            $service = new ClientValidationService();

            // Unknown client
            $result = $service->validate('-XX1234-xxxxxxxxxxxx');

            expect($result['valid'])->toBeFalse();
            expect($result['reason'])->toContain('not allowed');
        });

        it('rejects peer_id that does not match pattern', function () {
            $service = new ClientValidationService();

            // Invalid peer_id format
            $result = $service->validate('invalid_peer_id_here');

            expect($result['valid'])->toBeFalse();
        });
    });

    describe('blacklist mode', function () {
        beforeEach(function () {
            config()->set('bloodhound.client_validation.mode', 'blacklist');
        });

        it('allows unknown clients', function () {
            $service = new ClientValidationService();

            $result = $service->validate('-XX1234-xxxxxxxxxxxx');

            expect($result['valid'])->toBeTrue();
        });

        it('rejects blacklisted Xunlei client', function () {
            $service = new ClientValidationService();

            $result = $service->validate('-XL1234-xxxxxxxxxxxx');

            expect($result['valid'])->toBeFalse();
            expect($result['reason'])->toContain('banned');
        });

        it('rejects blacklisted Thunder client', function () {
            $service = new ClientValidationService();

            $result = $service->validate('-SD1234-xxxxxxxxxxxx');

            expect($result['valid'])->toBeFalse();
            expect($result['reason'])->toContain('banned');
        });

        it('allows valid clients in blacklist mode', function () {
            $service = new ClientValidationService();

            $result = $service->validate('-qB4500-xxxxxxxxxxxx');

            expect($result['valid'])->toBeTrue();
        });
    });

    describe('disabled validation', function () {
        it('allows any client when disabled', function () {
            config()->set('bloodhound.client_validation.enabled', false);

            $service = new ClientValidationService();

            $result = $service->validate('-XL1234-xxxxxxxxxxxx');

            expect($result['valid'])->toBeTrue();
        });
    });

    describe('version comparison', function () {
        it('handles blocked versions', function () {
            config()->set('bloodhound.whitelist.qBittorrent.blocked_versions', ['4.4.0', '4.4.1']);

            $service = new ClientValidationService();

            // Blocked version
            $result = $service->validate('-qB4400-xxxxxxxxxxxx');
            expect($result['valid'])->toBeFalse();
            expect($result['reason'])->toContain('blocked');

            // Non-blocked version
            $result = $service->validate('-qB4500-xxxxxxxxxxxx');
            expect($result['valid'])->toBeTrue();
        });

        it('handles max version', function () {
            config()->set('bloodhound.whitelist.qBittorrent.max_version', '4.5.0');

            $service = new ClientValidationService();

            // At max version
            $result = $service->validate('-qB4500-xxxxxxxxxxxx');
            expect($result['valid'])->toBeTrue();

            // Above max version
            $result = $service->validate('-qB4600-xxxxxxxxxxxx');
            expect($result['valid'])->toBeFalse();
            expect($result['reason'])->toContain('maximum version');
        });
    });

    describe('client identification', function () {
        it('identifies client from peer_id', function () {
            $service = new ClientValidationService();

            $info = $service->identifyClient('-qB4500-xxxxxxxxxxxx');

            expect($info)->not->toBeNull();
            expect($info['name'])->toBe('qBittorrent');
            expect($info['version'])->toBe('4.5.0');
        });

        it('returns null for unknown client', function () {
            $service = new ClientValidationService();

            $info = $service->identifyClient('-XX1234-xxxxxxxxxxxx');

            expect($info)->toBeNull();
        });
    });
});
