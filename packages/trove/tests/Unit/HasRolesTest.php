<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;
use Marque\Trove\Tests\TestUser;

describe('HasRoles trait', function () {
    it('casts role attribute to Role enum', function () {
        $user = TestUser::factory()->create(['role' => 'admin']);

        expect($user->role)->toBeInstanceOf(Role::class);
        expect($user->role)->toBe(Role::Admin);
    });

    it('checks if user is admin', function () {
        $admin = TestUser::factory()->admin()->create();
        $moderator = TestUser::factory()->moderator()->create();
        $user = TestUser::factory()->create();

        expect($admin->isAdmin())->toBeTrue();
        expect($moderator->isAdmin())->toBeFalse();
        expect($user->isAdmin())->toBeFalse();
    });

    it('checks if user is moderator or higher', function () {
        $admin = TestUser::factory()->admin()->create();
        $moderator = TestUser::factory()->moderator()->create();
        $uploader = TestUser::factory()->uploader()->create();
        $user = TestUser::factory()->create();

        expect($admin->isModerator())->toBeTrue();
        expect($moderator->isModerator())->toBeTrue();
        expect($uploader->isModerator())->toBeFalse();
        expect($user->isModerator())->toBeFalse();
    });

    it('checks if user is uploader or higher', function () {
        $admin = TestUser::factory()->admin()->create();
        $moderator = TestUser::factory()->moderator()->create();
        $uploader = TestUser::factory()->uploader()->create();
        $user = TestUser::factory()->create();

        expect($admin->isUploader())->toBeTrue();
        expect($moderator->isUploader())->toBeTrue();
        expect($uploader->isUploader())->toBeTrue();
        expect($user->isUploader())->toBeFalse();
    });

    it('checks hasRoleAtLeast', function () {
        $moderator = TestUser::factory()->moderator()->create();

        expect($moderator->hasRoleAtLeast(Role::User))->toBeTrue();
        expect($moderator->hasRoleAtLeast(Role::Uploader))->toBeTrue();
        expect($moderator->hasRoleAtLeast(Role::Moderator))->toBeTrue();
        expect($moderator->hasRoleAtLeast(Role::Admin))->toBeFalse();
    });
});
