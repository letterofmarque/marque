<?php

declare(strict_types=1);

use Marque\Usarrs\Enums\UserStatus;

describe('UserStatus', function () {
    it('has expected cases', function () {
        expect(UserStatus::cases())->toHaveCount(4);
    });

    it('only allows active users to login', function () {
        expect(UserStatus::Active->canLogin())->toBeTrue();
        expect(UserStatus::Banned->canLogin())->toBeFalse();
        expect(UserStatus::Disabled->canLogin())->toBeFalse();
        expect(UserStatus::Pending->canLogin())->toBeFalse();
    });
});
