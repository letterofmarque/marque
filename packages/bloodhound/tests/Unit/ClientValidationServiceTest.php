<?php

declare(strict_types=1);

use Marque\Bloodhound\Services\ClientValidationService;

beforeEach(function () {
    // Set up default whitelist config matching the service expectations
    config()->set('bloodhound.client_validation.enabled', true);
    config()->set('bloodhound.client_validation.mode', 'whitelist');
    config()->set('bloodhound.whitelist', [
        'qBittorrent' => [
            'peer_id_pattern' => '/^-qB(\d)(\d)(\d{2})-/',
            'version_format' => '%d.%d.%d',
            'min_version' => '4.3.0',
        ],
        'Deluge' => [
            'peer_id_pattern' => '/^-DE(\d)(\d)(\d)(\d)-/',
            'version_format' => '%d.%d.%d.%d',
            'min_version' => '2.0.0.0',
        ],
        'Transmission' => [
            'peer_id_pattern' => '/^-TR(\d)(\d)(\d{2})-/',
            'version_format' => '%d.%d.%d',
            'min_version' => '3.0.0',
        ],
    ]);
    // Blacklist uses pattern => reason format
    config()->set('bloodhound.blacklist', [
        '-XL' => 'Xunlei is banned',
        '-SD' => 'Thunder is banned',
    ]);
});

describe('ClientValidationService', function () {
    describe('whitelist mode', function () {
        it('allows valid qBittorrent client', function () {
            $service = new ClientValidationService();

            // qBittorrent 4.5.0 (format: -qBMmPP- where M=major, m=minor, PP=patch)
            $result = $service->validate('-qB4500-xxxxxxxxxxxx');

            expect($result['valid'])->toBeTrue();
            expect($result['client'])->toBe('qBittorrent');
            expect($result['version'])->toBe('4.5.0');
        });

        it('allows valid Deluge client', function () {
            $service = new ClientValidationService();

            // Deluge 2.1.1.0
            $result = $service->validate('-DE2110-xxxxxxxxxxxx');

            expect($result['valid'])->toBeTrue();
            expect($result['client'])->toBe('Deluge');
            expect($result['version'])->toBe('2.1.1.0');
        });

        it('allows valid Transmission client', function () {
            $service = new ClientValidationService();

            // Transmission 3.0.0
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
            expect($result['reason'])->toContain('too old');
        });

        it('rejects unknown client in whitelist mode', function () {
            $service = new ClientValidationService();

            // Unknown client
            $result = $service->validate('-XX1234-xxxxxxxxxxxx');

            expect($result['valid'])->toBeFalse();
            expect($result['reason'])->toBe('Client not in whitelist');
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
            expect($result['reason'])->toBe('Xunlei is banned');
        });

        it('rejects blacklisted Thunder client', function () {
            $service = new ClientValidationService();

            $result = $service->validate('-SD1234-xxxxxxxxxxxx');

            expect($result['valid'])->toBeFalse();
            expect($result['reason'])->toBe('Thunder is banned');
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
            config()->set('bloodhound.whitelist.qBittorrent.blocked_versions', ['4.4.0']);

            $service = new ClientValidationService();

            // Blocked version
            $result = $service->validate('-qB4400-xxxxxxxxxxxx');
            expect($result['valid'])->toBeFalse();
            expect($result['reason'])->toContain('blocked');
        });

        it('handles max version', function () {
            config()->set('bloodhound.whitelist.qBittorrent.max_version', '4.4.99');

            $service = new ClientValidationService();

            // Above max version
            $result = $service->validate('-qB4600-xxxxxxxxxxxx');
            expect($result['valid'])->toBeFalse();
            expect($result['reason'])->toContain('too new');
        });
    });

    describe('client identification', function () {
        it('identifies client from peer_id', function () {
            $service = new ClientValidationService();

            $info = $service->identify('-qB4500-xxxxxxxxxxxx');

            expect($info['client'])->toBe('qBittorrent');
            expect($info['version'])->toBe('4.5.0');
        });

        it('returns null version for unknown client', function () {
            $service = new ClientValidationService();

            $info = $service->identify('-XX1234-xxxxxxxxxxxx');

            expect($info['version'])->toBeNull();
        });
    });
});
