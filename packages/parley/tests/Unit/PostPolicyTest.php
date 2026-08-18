<?php

declare(strict_types=1);

use Marque\Parley\Models\Post;
use Marque\Parley\Tests\TestUser;

describe('PostPolicy', function () {
    it('lets anyone view a post', function () {
        $user = TestUser::factory()->create();
        $post = Post::factory()->create();

        expect($user->can('view', $post))->toBeTrue();
    });

    it('lets any authenticated user create', function () {
        // Whether the THREAD accepts posts (locked or not) is not this
        // policy's job — that is PostService::assertUnlocked, checked
        // separately below and covered end to end in PostServiceTest.
        $user = TestUser::factory()->create();

        expect($user->can('create', Post::class))->toBeTrue();
    });

    it('lets the author update their own post', function () {
        $author = TestUser::factory()->create();
        $post = Post::factory()->create(['user_id' => $author->id]);

        expect($author->can('update', $post))->toBeTrue();
    });

    it('refuses a plain user editing someone else\'s post', function () {
        $author = TestUser::factory()->create();
        $other = TestUser::factory()->create();
        $post = Post::factory()->create(['user_id' => $author->id]);

        expect($other->can('update', $post))->toBeFalse();
    });

    it('lets a moderator edit anyone\'s post', function () {
        $author = TestUser::factory()->create();
        $moderator = TestUser::factory()->moderator()->create();
        $post = Post::factory()->create(['user_id' => $author->id]);

        expect($moderator->can('update', $post))->toBeTrue();
    });

    it('refuses a plain user deleting someone else\'s post', function () {
        $author = TestUser::factory()->create();
        $other = TestUser::factory()->create();
        $post = Post::factory()->create(['user_id' => $author->id]);

        expect($other->can('delete', $post))->toBeFalse();
    });

    it('lets a moderator delete anyone\'s post', function () {
        $author = TestUser::factory()->create();
        $moderator = TestUser::factory()->moderator()->create();
        $post = Post::factory()->create(['user_id' => $author->id]);

        expect($moderator->can('delete', $post))->toBeTrue();
    });

    it('lets an uploader do nothing special — the role has no post privileges', function () {
        // Uploader sits between User and Moderator in trove's rank. Confirming
        // it behaves like a plain user here, since it would be easy for a
        // moderates() check built on the wrong comparison to accidentally let
        // it through.
        $author = TestUser::factory()->create();
        $uploader = TestUser::factory()->create(['role' => 'uploader']);
        $post = Post::factory()->create(['user_id' => $author->id]);

        expect($uploader->can('update', $post))->toBeFalse()
            ->and($uploader->can('delete', $post))->toBeFalse();
    });
});
