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

    it('never refuses a user with no status — an app without the column has no notion of a ban', function () {
        expect(UserStatus::refusalFor(new class
        {
            public function getAttribute(string $key): mixed
            {
                return null;
            }
        }))->toBeNull();

        expect(UserStatus::refusalFor(new stdClass))->toBeNull();
    });

    it('refuses a status it does not recognise, and says why for the ones it does', function () {
        $user = fn (mixed $status) => new class($status)
        {
            public function __construct(private mixed $status) {}

            public function getAttribute(string $key): mixed
            {
                return $this->status;
            }
        };

        expect(UserStatus::refusalFor($user('active')))->toBeNull()
            ->and(UserStatus::refusalFor($user(UserStatus::Active)))->toBeNull()
            ->and(UserStatus::refusalFor($user('frozen')))->toBe('This account is not active.')
            ->and(UserStatus::refusalFor($user(UserStatus::Banned)))->toBe('This account has been banned.');
    });
});
