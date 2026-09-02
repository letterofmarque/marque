<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Policies\TorrentPolicy;
use Marque\Trove\Tests\TestUser;

describe('TorrentPolicy', function () {
    describe('create', function () {
        it('denies regular user from creating torrents', function () {
            $user = TestUser::factory()->create();

            expect($user->can('create', Torrent::class))->toBeFalse();
        });

        it('allows uploader to create torrents', function () {
            $uploader = TestUser::factory()->uploader()->create();

            expect($uploader->can('create', Torrent::class))->toBeTrue();
        });

        it('allows moderator to create torrents', function () {
            $moderator = TestUser::factory()->moderator()->create();

            expect($moderator->can('create', Torrent::class))->toBeTrue();
        });

        it('allows admin to create torrents', function () {
            $admin = TestUser::factory()->admin()->create();

            expect($admin->can('create', Torrent::class))->toBeTrue();
        });
    });

    describe('update', function () {
        it('allows owner to update their torrent', function () {
            $user = TestUser::factory()->create();
            $torrent = Torrent::factory()->for($user, 'user')->create();

            expect($user->can('update', $torrent))->toBeTrue();
        });

        it('denies non-owner from updating torrent', function () {
            $owner = TestUser::factory()->create();
            $other = TestUser::factory()->create();
            $torrent = Torrent::factory()->for($owner, 'user')->create();

            expect($other->can('update', $torrent))->toBeFalse();
        });

        it('allows moderator to update any torrent', function () {
            $owner = TestUser::factory()->create();
            $moderator = TestUser::factory()->moderator()->create();
            $torrent = Torrent::factory()->for($owner, 'user')->create();

            expect($moderator->can('update', $torrent))->toBeTrue();
        });

        it('allows admin to update any torrent', function () {
            $owner = TestUser::factory()->create();
            $admin = TestUser::factory()->admin()->create();
            $torrent = Torrent::factory()->for($owner, 'user')->create();

            expect($admin->can('update', $torrent))->toBeTrue();
        });

        it('denies uploader from updating others torrent', function () {
            $owner = TestUser::factory()->create();
            $uploader = TestUser::factory()->uploader()->create();
            $torrent = Torrent::factory()->for($owner, 'user')->create();

            expect($uploader->can('update', $torrent))->toBeFalse();
        });
    });

    describe('delete', function () {
        it('denies regular user from deleting torrent', function () {
            $user = TestUser::factory()->create();
            $torrent = Torrent::factory()->for($user, 'user')->create();

            expect($user->can('delete', $torrent))->toBeFalse();
        });

        it('denies uploader from deleting torrent', function () {
            $uploader = TestUser::factory()->uploader()->create();
            $torrent = Torrent::factory()->for($uploader, 'user')->create();

            expect($uploader->can('delete', $torrent))->toBeFalse();
        });

        it('allows moderator to delete any torrent', function () {
            $owner = TestUser::factory()->create();
            $moderator = TestUser::factory()->moderator()->create();
            $torrent = Torrent::factory()->for($owner, 'user')->create();

            expect($moderator->can('delete', $torrent))->toBeTrue();
        });

        it('allows admin to delete any torrent', function () {
            $owner = TestUser::factory()->create();
            $admin = TestUser::factory()->admin()->create();
            $torrent = Torrent::factory()->for($owner, 'user')->create();

            expect($admin->can('delete', $torrent))->toBeTrue();
        });
    });
    describe('view', function () {
        it('allows anyone to view an unrestricted torrent', function () {
            $torrent = Torrent::factory()->create();

            expect(TestUser::factory()->create()->can('view', $torrent))->toBeTrue();
        });

        it('denies a user below the minimum role', function () {
            $torrent = Torrent::factory()->restrictedTo(Role::Uploader)->create();

            expect(TestUser::factory()->create()->can('view', $torrent))->toBeFalse();
        });

        it('allows a user at or above the minimum role', function () {
            $torrent = Torrent::factory()->restrictedTo(Role::Uploader)->create();

            expect(TestUser::factory()->uploader()->create()->can('view', $torrent))->toBeTrue()
                ->and(TestUser::factory()->admin()->create()->can('view', $torrent))->toBeTrue();
        });

        it('denies a guest a restricted torrent but allows an unrestricted one', function () {
            $policy = new TorrentPolicy;

            expect($policy->view(null, Torrent::factory()->restrictedTo(Role::Uploader)->create()))->toBeFalse()
                ->and($policy->view(null, Torrent::factory()->create()))->toBeTrue();
        });

        // The owner is deliberately not special-cased: an uploader demoted
        // below a torrent's min_role loses access to their own upload, which
        // is the point of a restriction level.
        it('does not exempt the torrent owner from the restriction', function () {
            $owner = TestUser::factory()->create();
            $torrent = Torrent::factory()->for($owner, 'user')->restrictedTo(Role::Moderator)->create();

            expect($owner->can('view', $torrent))->toBeFalse();
        });
    });
});
