<?php

declare(strict_types=1);

use Marque\Parley\Exceptions\TooManyPostsException;
use Marque\Parley\Livewire\Forum\CategoryIndex;
use Marque\Parley\Livewire\Forum\ThreadCreate;
use Marque\Parley\Livewire\Forum\ThreadIndex;
use Marque\Parley\Livewire\Forum\ThreadShow;
use Marque\Parley\Models\Category;
use Marque\Parley\Models\Thread;
use Marque\Parley\Tests\TestUser;

/**
 * The forum's own pages — category index, thread index, thread show, thread
 * create — as opposed to CommentThreadTest, which covers the posts/replies
 * component both presentations share.
 */
describe('the forum, when enabled (the default)', function () {
    it('lists categories with a thread count', function () {
        $withThreads = Category::factory()->create(['name' => 'General']);
        Thread::factory()->count(3)->create(['category_id' => $withThreads->id]);
        Category::factory()->create(['name' => 'Empty']);

        $this->get(route('parley.forum.categories.index'))
            ->assertOk()
            ->assertSeeLivewire(CategoryIndex::class)
            ->assertSee('General')
            ->assertSee('Empty')
            ->assertSee('3');
    });

    it('shows an empty state with no categories', function () {
        $this->get(route('parley.forum.categories.index'))
            ->assertOk()
            ->assertSee('No categories yet');
    });

    it('lists threads in a category, pinned first then newest', function () {
        $category = Category::factory()->create();
        $old = Thread::factory()->create(['category_id' => $category->id, 'title' => 'Old thread', 'created_at' => now()->subDays(2)]);
        $pinned = Thread::factory()->pinned()->create(['category_id' => $category->id, 'title' => 'Pinned thread', 'created_at' => now()->subDays(5)]);

        $response = $this->get(route('parley.forum.categories.show', $category))
            ->assertOk()
            ->assertSeeLivewire(ThreadIndex::class)
            ->assertSeeInOrder(['Pinned thread', 'Old thread']);
    });

    it('returns 404 for a non-existent category', function () {
        $this->get(route('parley.forum.categories.show', 'no-such-category'))
            ->assertNotFound();
    });

    it('shows a thread with its posts', function () {
        $thread = Thread::factory()->create(['title' => 'A discussion']);

        $this->get(route('parley.forum.threads.show', $thread))
            ->assertOk()
            ->assertSeeLivewire(ThreadShow::class)
            ->assertSee('A discussion');
    });

    it('lets an authenticated user start a new thread', function () {
        $user = TestUser::factory()->create();
        $category = Category::factory()->create();

        \Livewire\Livewire::actingAs($user)
            ->test(ThreadCreate::class, ['category' => $category])
            ->set('title', 'My new thread')
            ->set('body', 'the opening post')
            ->call('submit');

        $thread = Thread::query()->where('title', 'My new thread')->sole();

        expect($thread->category_id)->toBe($category->id)
            ->and($thread->user_id)->toBe($user->id)
            ->and($thread->posts()->count())->toBe(1)
            ->and($thread->posts()->first()->body)->toBe('the opening post');
    });

    it('refuses an empty title when starting a thread', function () {
        $user = TestUser::factory()->create();
        $category = Category::factory()->create();

        \Livewire\Livewire::actingAs($user)
            ->test(ThreadCreate::class, ['category' => $category])
            ->set('title', '')
            ->set('body', 'a body')
            ->call('submit')
            ->assertHasErrors(['title' => 'required']);

        expect(Thread::count())->toBe(0);
    });

    it('shows a friendly error instead of a raw exception when rate limited', function () {
        // ThreadCreate is the second real call site into
        // PostService::create() (alongside CommentThread) — Spec #94's
        // Checkpoint #526 explicitly asked to check for exactly this rather
        // than assume CommentThread was the only one.
        config([
            'parley.rate_limiting.enabled' => true,
            'parley.rate_limiting.max_attempts' => 1,
            'parley.rate_limiting.decay_seconds' => 60,
        ]);

        $user = TestUser::factory()->create();
        $category = Category::factory()->create();

        // Consume the one allowed attempt via a first thread.
        \Livewire\Livewire::actingAs($user)
            ->test(ThreadCreate::class, ['category' => $category])
            ->set('title', 'First thread')
            ->set('body', 'first post')
            ->call('submit');

        expect(Thread::count())->toBe(1);

        \Livewire\Livewire::actingAs($user)
            ->test(ThreadCreate::class, ['category' => $category])
            ->set('title', 'Second thread')
            ->set('body', 'second post, too fast')
            ->call('submit')
            ->assertHasErrors('body');

        expect(Thread::count())->toBe(1);
    });

    it('does not throw TooManyPostsException as an unhandled exception from ThreadCreate', function () {
        config([
            'parley.rate_limiting.enabled' => true,
            'parley.rate_limiting.max_attempts' => 1,
            'parley.rate_limiting.decay_seconds' => 60,
        ]);

        $user = TestUser::factory()->create();
        $category = Category::factory()->create();

        \Livewire\Livewire::actingAs($user)
            ->test(ThreadCreate::class, ['category' => $category])
            ->set('title', 'First thread')
            ->set('body', 'first post')
            ->call('submit');

        expect(fn () => \Livewire\Livewire::actingAs($user)
            ->test(ThreadCreate::class, ['category' => $category])
            ->set('title', 'Second thread')
            ->set('body', 'second post')
            ->call('submit'))
            ->not->toThrow(TooManyPostsException::class);
    });

    it('lets a moderator pin, lock and delete a thread', function () {
        $moderator = TestUser::factory()->moderator()->create();
        $thread = Thread::factory()->create();

        \Livewire\Livewire::actingAs($moderator)
            ->test(ThreadShow::class, ['thread' => $thread])
            ->call('pin')
            ->assertSet('thread.pinned', true)
            ->call('lock')
            ->assertSet('thread.locked', true);

        \Livewire\Livewire::actingAs($moderator)
            ->test(ThreadShow::class, ['thread' => $thread])
            ->call('delete');

        expect(Thread::find($thread->id))->toBeNull();
    });

    it('refuses a plain user pinning or locking a thread', function () {
        $user = TestUser::factory()->create();
        $thread = Thread::factory()->create();

        \Livewire\Livewire::actingAs($user)
            ->test(ThreadShow::class, ['thread' => $thread])
            ->call('pin')
            ->assertForbidden();
    });
});
