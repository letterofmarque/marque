<?php

declare(strict_types=1);

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Marque\Parley\Exceptions\ThreadLockedException;
use Marque\Parley\Exceptions\TooManyPostsException;
use Marque\Parley\Livewire\CommentThread;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;
use Marque\Parley\Tests\TestSubject;
use Marque\Parley\Tests\TestUser;

describe('CommentThread', function () {
    it('refuses to mount with neither a subject nor a thread', function () {
        // Livewire surfaces a mount()-time exception wrapped in a
        // ViewException during a full render pass — the message from the
        // guard in CommentThread::mount() still comes through.
        expect(fn () => Livewire::test(CommentThread::class))
            ->toThrow('CommentThread needs either $subject');
    });

    it('mounts directly on a forum thread, the other of the two presentations', function () {
        $thread = Thread::factory()->create();

        Livewire::test(CommentThread::class, ['thread' => $thread])
            ->assertSee('No comments yet');
    });

    it('posts into a forum thread mounted directly, without a subject', function () {
        $user = TestUser::factory()->create();
        $thread = Thread::factory()->create();

        Livewire::actingAs($user)
            ->test(CommentThread::class, ['thread' => $thread])
            ->set('body', 'first reply in the forum')
            ->call('submit')
            ->assertSee('first reply in the forum');

        expect($thread->fresh()->posts()->count())->toBe(1);
    });

    it('shows an empty state for a subject with no comments yet', function () {
        $subject = TestSubject::create(['name' => 'a thing']);

        Livewire::test(CommentThread::class, ['subject' => $subject])
            ->assertSee('No comments yet');
    });

    it('creates no thread just from being rendered', function () {
        // Reading must stay lazy — a torrent nobody has commented on holds no
        // row, matching HasThreads::comments() and the reasoning in
        // ThreadService::threadFor().
        $subject = TestSubject::create(['name' => 'a thing']);

        Livewire::test(CommentThread::class, ['subject' => $subject]);

        expect(Thread::count())->toBe(0);
    });

    it('lets an authenticated user post the first comment, creating the thread lazily', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        Livewire::actingAs($user)
            ->test(CommentThread::class, ['subject' => $subject])
            ->set('body', 'hello there')
            ->call('submit')
            ->assertSee('hello there')
            ->assertSet('body', '');

        expect(Thread::count())->toBe(1)
            ->and(Post::query()->sole()->body)->toBe('hello there');
    });

    it('renders the post body through squidink rather than printing it raw', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($user->id);
        Post::factory()->inThread($thread)->format('markdown', '**bold**')->create();

        Livewire::test(CommentThread::class, ['subject' => $subject])
            ->assertSee('<strong>bold</strong>', escape: false);
    });

    it('refuses an empty comment', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        Livewire::actingAs($user)
            ->test(CommentThread::class, ['subject' => $subject])
            ->set('body', '')
            ->call('submit')
            ->assertHasErrors(['body' => 'required']);

        expect(Post::count())->toBe(0);
    });

    it('rejects a post over the configured length', function () {
        config(['parley.format.max_length' => 10]);

        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        Livewire::actingAs($user)
            ->test(CommentThread::class, ['subject' => $subject])
            ->set('body', str_repeat('x', 11))
            ->call('submit')
            ->assertHasErrors(['body' => 'max']);
    });

    it('replies to a post, nesting it under the parent', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($user->id);
        $root = Post::factory()->inThread($thread)->create(['body' => 'root post']);

        Livewire::actingAs($user)
            ->test(CommentThread::class, ['subject' => $subject])
            ->call('startReply', $root->id)
            ->assertSet('replyingTo', $root->id)
            ->set('body', 'a reply')
            ->call('submit');

        $reply = Post::query()->where('body', 'a reply')->sole();

        expect($reply->reply_to_id)->toBe($root->id)
            ->and($reply->thread_id)->toBe($thread->id);
    });

    it('refuses a post on a locked thread', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $subject->comments($user->id)->update(['locked' => true]);

        Livewire::actingAs($user)
            ->test(CommentThread::class, ['subject' => $subject])
            ->set('body', 'too late')
            ->call('submit');
    })->throws(ThreadLockedException::class);

    it('lets the author edit their own post', function () {
        $author = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($author->id);
        $post = Post::factory()->inThread($thread)->create(['user_id' => $author->id, 'body' => 'original']);

        Livewire::actingAs($author)
            ->test(CommentThread::class, ['subject' => $subject])
            ->call('startEdit', $post->id)
            ->assertSet('editingBody', 'original')
            ->set('editingBody', 'edited')
            ->call('saveEdit');

        expect($post->fresh()->body)->toBe('edited');
    });

    it('still lets the author edit their own post on a locked thread, by default', function () {
        // config('parley.moderation.lock_blocks_edits') defaults to false —
        // "locked" means no new posts, not "frozen". See PostServiceTest for
        // the service-layer coverage of both config states.
        $author = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($author->id);
        $post = Post::factory()->inThread($thread)->create(['user_id' => $author->id, 'body' => 'original']);
        $thread->update(['locked' => true]);

        Livewire::actingAs($author)
            ->test(CommentThread::class, ['subject' => $subject])
            ->call('startEdit', $post->id)
            ->set('editingBody', 'edited')
            ->call('saveEdit');

        expect($post->fresh()->body)->toBe('edited');
    });

    it('hides edit and delete once a locked thread has lock_blocks_edits enabled', function () {
        config(['parley.moderation.lock_blocks_edits' => true]);

        $author = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($author->id);
        Post::factory()->inThread($thread)->create(['user_id' => $author->id, 'body' => 'original']);
        $thread->update(['locked' => true]);

        // Hidden at the view layer, not just refused server-side — an author
        // should never see a button that would throw ThreadLockedException.
        Livewire::actingAs($author)
            ->test(CommentThread::class, ['subject' => $subject])
            ->assertDontSee('wire:click="startEdit', escape: false)
            ->assertDontSee('wire:click="delete', escape: false);
    });

    it('refuses editing someone else\'s post', function () {
        $author = TestUser::factory()->create();
        $other = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($author->id);
        $post = Post::factory()->inThread($thread)->create(['user_id' => $author->id]);

        Livewire::actingAs($other)
            ->test(CommentThread::class, ['subject' => $subject])
            ->call('startEdit', $post->id)
            ->assertForbidden();
    });

    it('lets the author delete their own post', function () {
        $author = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($author->id);
        $post = Post::factory()->inThread($thread)->create(['user_id' => $author->id]);

        Livewire::actingAs($author)
            ->test(CommentThread::class, ['subject' => $subject])
            ->call('delete', $post->id);

        expect(Post::find($post->id))->toBeNull();
    });

    it('refuses deleting someone else\'s post', function () {
        $author = TestUser::factory()->create();
        $other = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($author->id);
        $post = Post::factory()->inThread($thread)->create(['user_id' => $author->id]);

        Livewire::actingAs($other)
            ->test(CommentThread::class, ['subject' => $subject])
            ->call('delete', $post->id)
            ->assertForbidden();

        expect(Post::find($post->id))->not->toBeNull();
    });

    it('lets a moderator delete any post', function () {
        $author = TestUser::factory()->create();
        $moderator = TestUser::factory()->moderator()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($author->id);
        $post = Post::factory()->inThread($thread)->create(['user_id' => $author->id]);

        Livewire::actingAs($moderator)
            ->test(CommentThread::class, ['subject' => $subject])
            ->call('delete', $post->id);

        expect(Post::find($post->id))->toBeNull();
    });

    it('shows a deleted post as a placeholder rather than removing it from the tree', function () {
        // Soft delete only — a reply chain must not vanish because its parent
        // was removed. See PostService::delete().
        $author = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);
        $thread = $subject->comments($author->id);
        $root = Post::factory()->inThread($thread)->create(['user_id' => $author->id, 'body' => 'root']);
        Post::factory()->replyTo($root)->create(['body' => 'a reply that must survive']);

        Livewire::actingAs($author)
            ->test(CommentThread::class, ['subject' => $subject])
            ->call('delete', $root->id)
            ->assertSee('[deleted]')
            ->assertSee('a reply that must survive');
    });

    it('hides the composer for a guest', function () {
        $subject = TestSubject::create(['name' => 'a thing']);

        Livewire::test(CommentThread::class, ['subject' => $subject])
            ->assertDontSee('wire:submit="submit"', escape: false);
    });

    it('shows a friendly error instead of a raw exception when rate limited', function () {
        // Unlike the locked-thread case above (which still bubbles as an
        // unhandled exception — a pre-existing gap, out of scope here),
        // TooManyPostsException must never reach the user as a 500. See
        // Spec #94's Failure mode section.
        config([
            'parley.rate_limiting.enabled' => true,
            'parley.rate_limiting.max_attempts' => 1,
            'parley.rate_limiting.decay_seconds' => 60,
        ]);

        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        $component = Livewire::actingAs($user)
            ->test(CommentThread::class, ['subject' => $subject])
            ->set('body', 'first post')
            ->call('submit');

        expect(Post::count())->toBe(1);

        $component->set('body', 'second post, too fast')
            ->call('submit')
            ->assertHasErrors('body');

        expect(Post::count())->toBe(1);
    });

    it('does not throw TooManyPostsException as an unhandled exception', function () {
        config([
            'parley.rate_limiting.enabled' => true,
            'parley.rate_limiting.max_attempts' => 1,
            'parley.rate_limiting.decay_seconds' => 60,
        ]);

        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'a thing']);

        Livewire::actingAs($user)
            ->test(CommentThread::class, ['subject' => $subject])
            ->set('body', 'first post')
            ->call('submit');

        expect(fn () => Livewire::actingAs($user)
            ->test(CommentThread::class, ['subject' => $subject])
            ->set('body', 'second post')
            ->call('submit'))
            ->not->toThrow(TooManyPostsException::class);
    });
});

// #11135's sweep: a CommentThread acts on its own thread and nothing else. The
// thread it posts into and the post being replied to or edited were plain public
// properties, so a crafted Livewire update could point submit() at any thread
// in the database; and startReply() took any post id, so a reply landed in that
// post's thread, wherever it was.
describe('CommentThread state from the client', function () {
    it('refuses an update to the state that says which thread and post it acts on', function (string $property) {
        $thread = Thread::factory()->create();

        Livewire::actingAs(TestUser::factory()->create())
            ->test(CommentThread::class, ['thread' => $thread])
            ->set($property, 999);
    })->with(['threadId', 'replyingTo', 'editingPost'])
        ->throws(CannotUpdateLockedPropertyException::class);

    it('refuses to reply to a post in another thread, and posts nothing there', function () {
        $user = TestUser::factory()->create();
        $here = Thread::factory()->create();
        $elsewhere = Thread::factory()->create();
        $foreign = Post::factory()->inThread($elsewhere)->create();

        Livewire::actingAs($user)
            ->test(CommentThread::class, ['thread' => $here])
            ->call('startReply', $foreign->id)
            ->assertNotFound();

        expect($elsewhere->posts()->count())->toBe(1)
            ->and($here->posts()->count())->toBe(0);
    });

    it('refuses to reply before its thread exists, when no post can be in it', function () {
        $user = TestUser::factory()->create();
        $subject = TestSubject::create(['name' => 'nobody has commented']);
        $foreign = Post::factory()->inThread(Thread::factory()->create())->create();

        Livewire::actingAs($user)
            ->test(CommentThread::class, ['subject' => $subject])
            ->call('startReply', $foreign->id)
            ->assertNotFound();
    });

    it('refuses to edit or delete a post in another thread, even one the user wrote', function (string $method) {
        $user = TestUser::factory()->create();
        $here = Thread::factory()->create();
        $theirs = Post::factory()->inThread(Thread::factory()->create())->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test(CommentThread::class, ['thread' => $here])
            ->call($method, $theirs->id)
            ->assertNotFound();

        expect(Post::query()->find($theirs->id))->not->toBeNull();
    })->with(['startEdit', 'delete']);
});
