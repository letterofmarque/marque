<?php

declare(strict_types=1);

use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Models\Category;
use Marque\Parley\Models\Thread;
use Marque\Parley\Tests\TestSubject;
use Marque\Parley\Tests\TestUser;

function threadService(): ThreadServiceInterface
{
    return app(ThreadServiceInterface::class);
}

describe('ThreadService', function () {
    it('creates a forum thread in a category', function () {
        $user = TestUser::factory()->create();
        $category = Category::factory()->create();

        $thread = threadService()->create($user, $category, 'A new thread');

        expect($thread->title)->toBe('A new thread')
            ->and($thread->category_id)->toBe($category->id)
            ->and($thread->user_id)->toBe($user->id)
            ->and($thread->isForum())->toBeTrue();
    });

    it('resolves a comment thread for any subject via HasThreads', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        $thread = threadService()->forSubject($subject, $user);

        expect($thread->isComments())->toBeTrue()
            ->and($thread->threadable_id)->toBe($subject->id);
    });

    it('returns the same comment thread on a second call rather than creating another', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        $first = threadService()->forSubject($subject, $user);
        $second = threadService()->forSubject($subject->fresh(), $user);

        expect($second->id)->toBe($first->id)
            ->and(Thread::count())->toBe(1);
    });

    it('resolves a comment thread for a subject WITHOUT requiring HasThreads', function () {
        // threadFor() is the entry point a consumer uses when it cannot add
        // HasThreads to the model itself — see docs/integration.md,
        // "attaching to a model you don't own". Deliberately a bare model
        // with no HasThreads, unlike every other test in this file.
        $subject = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'test_subjects';

            protected $guarded = [];
        };
        $subject->save();

        $user = TestUser::factory()->create();

        $thread = threadService()->threadFor($subject, $user);

        expect($thread->isComments())->toBeTrue()
            ->and($thread->threadable_id)->toBe($subject->id)
            ->and($thread->threadable_type)->toBe($subject->getMorphClass());
    });

    it('returns the same thread from threadFor on a second call rather than creating another', function () {
        $subject = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'test_subjects';

            protected $guarded = [];
        };
        $subject->save();

        $user = TestUser::factory()->create();

        $first = threadService()->threadFor($subject, $user);
        $second = threadService()->threadFor($subject->fresh(), $user);

        expect($second->id)->toBe($first->id)
            ->and(Thread::count())->toBe(1);
    });

    it('finds the same thread whether resolved via HasThreads or threadFor', function () {
        // The two entry points must agree on identity — a consumer that owns
        // its model and uses HasThreads, and one that can't and uses
        // threadFor(), have to land on the same row for the same subject.
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        $viaTrait = threadService()->forSubject($subject, $user);
        $viaBareModel = threadService()->threadFor($subject->fresh(), $user);

        expect($viaBareModel->id)->toBe($viaTrait->id)
            ->and(Thread::count())->toBe(1);
    });

    it('refuses a subject that does not use HasThreads', function () {
        $subject = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'test_subjects';

            protected $guarded = [];
        };
        $subject->save();

        $user = TestUser::factory()->create();

        expect(fn () => threadService()->forSubject($subject, $user))
            ->toThrow(LogicException::class);
    });

    it('updates a thread title', function () {
        $thread = Thread::factory()->create(['title' => 'old']);

        $updated = threadService()->update($thread, 'new');

        expect($updated->title)->toBe('new')
            ->and($thread->fresh()->title)->toBe('new');
    });

    it('soft-deletes a thread', function () {
        $thread = Thread::factory()->create();

        threadService()->delete($thread);

        expect(Thread::find($thread->id))->toBeNull()
            ->and(Thread::withTrashed()->find($thread->id))->not->toBeNull();
    });

    it('pins and unpins', function () {
        $thread = Thread::factory()->create();

        threadService()->pin($thread);
        expect($thread->fresh()->pinned)->toBeTrue();

        threadService()->unpin($thread);
        expect($thread->fresh()->pinned)->toBeFalse();
    });

    it('locks and unlocks', function () {
        $thread = Thread::factory()->create();

        threadService()->lock($thread);
        expect($thread->fresh()->locked)->toBeTrue();

        threadService()->unlock($thread);
        expect($thread->fresh()->locked)->toBeFalse();
    });

    it('paginates a category pinned-first then newest', function () {
        $category = Category::factory()->create();

        $old = Thread::factory()->create(['category_id' => $category->id, 'created_at' => now()->subDays(2)]);
        $new = Thread::factory()->create(['category_id' => $category->id, 'created_at' => now()]);
        $pinned = Thread::factory()->pinned()->create([
            'category_id' => $category->id,
            'created_at' => now()->subDays(5),
        ]);

        // A thread from a DIFFERENT category must not leak in.
        Thread::factory()->create();

        $page = threadService()->paginateForCategory($category, perPage: 10);

        expect($page->pluck('id')->all())->toBe([$pinned->id, $new->id, $old->id]);
    });

    it('paginates comment threads by threadable type for moderation tooling', function () {
        $subject = TestSubject::create(['name' => 'a']);
        Thread::factory()->on($subject)->create();
        Thread::factory()->create(); // a forum thread, must not appear

        $page = threadService()->paginateForThreadableType(TestSubject::class, perPage: 10);

        expect($page->total())->toBe(1)
            ->and($page->first()->threadable_type)->toBe((new TestSubject)->getMorphClass());
    });
});
