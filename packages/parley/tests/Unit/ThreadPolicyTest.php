<?php

declare(strict_types=1);

use Marque\Parley\Models\Thread;
use Marque\Parley\Tests\TestUser;

describe('ThreadPolicy', function () {
    it('lets anyone view a thread', function () {
        $user = TestUser::factory()->create();
        $thread = Thread::factory()->create();

        expect($user->can('view', $thread))->toBeTrue();
    });

    it('lets the owner update their own thread', function () {
        $owner = TestUser::factory()->create();
        $thread = Thread::factory()->create(['user_id' => $owner->id]);

        expect($owner->can('update', $thread))->toBeTrue();
    });

    it('refuses a plain user editing someone else\'s thread', function () {
        $owner = TestUser::factory()->create();
        $other = TestUser::factory()->create();
        $thread = Thread::factory()->create(['user_id' => $owner->id]);

        expect($other->can('update', $thread))->toBeFalse();
    });

    it('lets a moderator update someone else\'s thread', function () {
        $owner = TestUser::factory()->create();
        $moderator = TestUser::factory()->moderator()->create();
        $thread = Thread::factory()->create(['user_id' => $owner->id]);

        expect($moderator->can('update', $thread))->toBeTrue();
    });

    it('lets an admin update someone else\'s thread, since admin outranks moderator', function () {
        $owner = TestUser::factory()->create();
        $admin = TestUser::factory()->admin()->create();
        $thread = Thread::factory()->create(['user_id' => $owner->id]);

        expect($admin->can('update', $thread))->toBeTrue();
    });

    it('lets the owner delete their own thread', function () {
        $owner = TestUser::factory()->create();
        $thread = Thread::factory()->create(['user_id' => $owner->id]);

        expect($owner->can('delete', $thread))->toBeTrue();
    });

    it('refuses a plain user pinning even their own thread', function () {
        // Unlike update/delete, there is no "it's mine" case for pin/lock.
        $owner = TestUser::factory()->create();
        $thread = Thread::factory()->create(['user_id' => $owner->id]);

        expect($owner->can('pin', $thread))->toBeFalse();
    });

    it('lets a moderator pin any thread', function () {
        $moderator = TestUser::factory()->moderator()->create();
        $thread = Thread::factory()->create();

        expect($moderator->can('pin', $thread))->toBeTrue();
    });

    it('refuses a plain user locking a thread', function () {
        $user = TestUser::factory()->create();
        $thread = Thread::factory()->create();

        expect($user->can('lock', $thread))->toBeFalse();
    });

    it('lets a moderator lock a thread', function () {
        $moderator = TestUser::factory()->moderator()->create();
        $thread = Thread::factory()->create();

        expect($moderator->can('lock', $thread))->toBeTrue();
    });

    it('raises the moderation bar to admin-only via config', function () {
        config()->set('parley.moderation.role', 'admin');

        $moderator = TestUser::factory()->moderator()->create();
        $admin = TestUser::factory()->admin()->create();
        $thread = Thread::factory()->create();

        expect($moderator->can('lock', $thread))->toBeFalse()
            ->and($admin->can('lock', $thread))->toBeTrue();
    });
});
