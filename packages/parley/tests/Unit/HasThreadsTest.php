<?php

declare(strict_types=1);

use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;
use Marque\Parley\Tests\TestSubject;
use Marque\Parley\Tests\TestUser;

/**
 * The trait is what turns "discussion is polymorphic" from an architecture
 * diagram into one line in a consumer's model. TestSubject is a plain model
 * with `use HasThreads` and nothing else — if these pass, any model can have
 * comments.
 */
describe('HasThreads', function () {
    it('gives any model a threads relationship', function () {
        $subject = TestSubject::create(['name' => 'a thing']);
        Thread::factory()->on($subject)->create();

        expect($subject->threads)->toHaveCount(1);
    });

    it('creates the comment thread lazily on first use', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        // Nothing exists until someone asks — a site that never turns comments
        // on accumulates no empty threads.
        expect($subject->commentThread())->toBeNull();

        $thread = $subject->comments($user->id);

        expect($thread)->toBeInstanceOf(Thread::class)
            ->and($subject->fresh()->commentThread())->not->toBeNull();
    });

    it('returns the same thread on subsequent calls', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        $first = $subject->comments($user->id);
        $second = $subject->fresh()->comments($user->id);

        expect($second->id)->toBe($first->id)
            ->and(Thread::count())->toBe(1);
    });

    it('creates a comment-shaped thread, not a forum one', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        $thread = $subject->comments($user->id);

        expect($thread->isComments())->toBeTrue()
            ->and($thread->title)->toBeNull()
            ->and($thread->category_id)->toBeNull()
            ->and($thread->threadable_id)->toBe($subject->id);
    });

    it('counts posts across the discussion', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($user->id);

        expect($subject->commentCount())->toBe(0)
            ->and($subject->hasComments())->toBeFalse();

        Post::factory()->count(3)->inThread($thread)->create();

        expect($subject->commentCount())->toBe(3)
            ->and($subject->hasComments())->toBeTrue();
    });

    it('counts replies too, not just top-level posts', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($user->id);

        $root = Post::factory()->inThread($thread)->create();
        Post::factory()->replyTo($root)->create();
        Post::factory()->replyTo($root)->create();

        expect($subject->commentCount())->toBe(3);
    });

    it('finds the most recent post', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($user->id);

        Post::factory()->inThread($thread)->create(['created_at' => now()->subHour()]);
        $newest = Post::factory()->inThread($thread)->create(['created_at' => now()]);

        expect($subject->latestComment()->id)->toBe($newest->id);
    });

    it('reports nothing for a model with no discussion', function () {
        $subject = TestSubject::create(['name' => 'quiet']);

        expect($subject->commentCount())->toBe(0)
            ->and($subject->hasComments())->toBeFalse()
            ->and($subject->latestComment())->toBeNull();
    });

    it('keeps the discussions of different models separate', function () {
        $user = TestUser::factory()->create();
        $one = TestSubject::create(['name' => 'first']);
        $two = TestSubject::create(['name' => 'second']);

        Post::factory()->count(2)->inThread($one->comments($user->id))->create();
        Post::factory()->count(5)->inThread($two->comments($user->id))->create();

        expect($one->commentCount())->toBe(2)
            ->and($two->commentCount())->toBe(5);
    });
});
