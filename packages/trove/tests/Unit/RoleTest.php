<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;

describe('Role enum', function () {
    it('has expected cases', function () {
        expect(Role::cases())->toHaveCount(4);
        expect(Role::User->value)->toBe('user');
        expect(Role::Uploader->value)->toBe('uploader');
        expect(Role::Moderator->value)->toBe('moderator');
        expect(Role::Admin->value)->toBe('admin');
    });

    it('has correct rank ordering', function () {
        expect(Role::User->rank())->toBe(0);
        expect(Role::Uploader->rank())->toBe(1);
        expect(Role::Moderator->rank())->toBe(2);
        expect(Role::Admin->rank())->toBe(3);
    });

    it('compares roles with isAtLeast', function () {
        expect(Role::Admin->isAtLeast(Role::User))->toBeTrue();
        expect(Role::Admin->isAtLeast(Role::Moderator))->toBeTrue();
        expect(Role::Admin->isAtLeast(Role::Admin))->toBeTrue();

        expect(Role::Moderator->isAtLeast(Role::User))->toBeTrue();
        expect(Role::Moderator->isAtLeast(Role::Uploader))->toBeTrue();
        expect(Role::Moderator->isAtLeast(Role::Moderator))->toBeTrue();
        expect(Role::Moderator->isAtLeast(Role::Admin))->toBeFalse();

        expect(Role::User->isAtLeast(Role::User))->toBeTrue();
        expect(Role::User->isAtLeast(Role::Uploader))->toBeFalse();
    });

    it('compares roles with isHigherThan', function () {
        expect(Role::Admin->isHigherThan(Role::Moderator))->toBeTrue();
        expect(Role::Admin->isHigherThan(Role::Admin))->toBeFalse();
        expect(Role::User->isHigherThan(Role::User))->toBeFalse();
        expect(Role::User->isHigherThan(Role::Admin))->toBeFalse();
    });

    it('returns roles at or above a level', function () {
        $atLeastModerator = Role::atLeast(Role::Moderator);

        expect($atLeastModerator)->toContain(Role::Moderator);
        expect($atLeastModerator)->toContain(Role::Admin);
        expect($atLeastModerator)->not->toContain(Role::User);
        expect($atLeastModerator)->not->toContain(Role::Uploader);
    });

    it('can be created from string value', function () {
        expect(Role::from('admin'))->toBe(Role::Admin);
        expect(Role::from('moderator'))->toBe(Role::Moderator);
        expect(Role::from('uploader'))->toBe(Role::Uploader);
        expect(Role::from('user'))->toBe(Role::User);
    });

    it('returns null for invalid value with tryFrom', function () {
        expect(Role::tryFrom('invalid'))->toBeNull();
    });
});
