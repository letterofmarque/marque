<?php

declare(strict_types=1);

use Marque\Usarrs\Enums\AuthDriver;

describe('AuthDriver', function () {
    it('has expected cases', function () {
        expect(AuthDriver::cases())->toHaveCount(4);
        expect(AuthDriver::Password->value)->toBe('password');
        expect(AuthDriver::MagicLink->value)->toBe('magic_link');
        expect(AuthDriver::Socialite->value)->toBe('socialite');
        expect(AuthDriver::InviteOnly->value)->toBe('invite_only');
    });

    it('correctly reports registration support', function () {
        expect(AuthDriver::Password->supportsRegistration())->toBeTrue();
        expect(AuthDriver::MagicLink->supportsRegistration())->toBeTrue();
        expect(AuthDriver::Socialite->supportsRegistration())->toBeTrue();
        expect(AuthDriver::InviteOnly->supportsRegistration())->toBeFalse();
    });

    it('correctly reports password reset support', function () {
        expect(AuthDriver::Password->supportsPasswordReset())->toBeTrue();
        expect(AuthDriver::InviteOnly->supportsPasswordReset())->toBeTrue();
        expect(AuthDriver::MagicLink->supportsPasswordReset())->toBeFalse();
        expect(AuthDriver::Socialite->supportsPasswordReset())->toBeFalse();
    });

    it('correctly reports password requirement', function () {
        expect(AuthDriver::Password->requiresPassword())->toBeTrue();
        expect(AuthDriver::InviteOnly->requiresPassword())->toBeTrue();
        expect(AuthDriver::MagicLink->requiresPassword())->toBeFalse();
        expect(AuthDriver::Socialite->requiresPassword())->toBeFalse();
    });
});
