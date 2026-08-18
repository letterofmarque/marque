<?php

declare(strict_types=1);

use Marque\Parley\Contracts\PostServiceInterface;
use Marque\Parley\Exceptions\ThreadLockedException;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;
use Marque\Parley\Tests\TestUser;

function postService(): PostServiceInterface
{
    return app(PostServiceInterface::class);
}

describe('PostService', function () {
    it('creates a post in a thread', function () {
        $thread = Thread::factory()->create();
        $user = TestUser::factory()->create();

        $post = postService()->create($thread, $user, 'hello');

        expect($post->body)->toBe('hello')
            ->and($post->thread_id)->toBe($thread->id)
            ->and($post->user_id)->toBe($user->id)
            ->and($post->reply_to_id)->toBeNull();
    });

    it('records the parser a post was written with', function () {
        $thread = Thread::factory()->create();
        $user = TestUser::factory()->create();

        $post = postService()->create($thread, $user, '[b]hi[/b]', 'bbcode');

        expect($post->body_format)->toBe('bbcode')
            ->and($post->renderedBody())->toBe('<p><strong>hi</strong></p>');
    });

    it('replies to another post', function () {
        $thread = Thread::factory()->create();
        $user = TestUser::factory()->create();
        $root = postService()->create($thread, $user, 'root');

        $reply = postService()->reply($root, $user, 'a reply');

        expect($reply->reply_to_id)->toBe($root->id)
            ->and($reply->thread_id)->toBe($thread->id);
    });

    it('refuses a new post on a locked thread', function () {
        $thread = Thread::factory()->locked()->create();
        $user = TestUser::factory()->create();

        expect(fn () => postService()->create($thread, $user, 'too late'))
            ->toThrow(ThreadLockedException::class);

        expect($thread->posts)->toHaveCount(0);
    });

    it('refuses a reply when the thread the parent belongs to is locked', function () {
        $thread = Thread::factory()->create();
        $user = TestUser::factory()->create();
        $root = postService()->create($thread, $user, 'root');

        $thread->update(['locked' => true]);

        expect(fn () => postService()->reply($root, $user, 'too late'))
            ->toThrow(ThreadLockedException::class);
    });

    it('does NOT reject an update on a locked thread — editing is not posting', function () {
        $thread = Thread::factory()->create();
        $user = TestUser::factory()->create();
        $post = postService()->create($thread, $user, 'original');

        $thread->update(['locked' => true]);

        $updated = postService()->update($post, 'edited');

        expect($updated->body)->toBe('edited');
    });

    it('updates a post body and format together', function () {
        $post = Post::factory()->format('markdown', 'old')->create();

        $updated = postService()->update($post, 'new', 'bbcode');

        expect($updated->body)->toBe('new')
            ->and($updated->body_format)->toBe('bbcode');
    });

    it('soft-deletes a post without cascading to its replies', function () {
        $root = Post::factory()->create();
        $reply = Post::factory()->replyTo($root)->create();

        postService()->delete($root);

        expect(Post::find($root->id))->toBeNull()
            ->and(Post::find($reply->id))->not->toBeNull();
    });

    it('returns every post in a thread, oldest first, for building the tree', function () {
        $thread = Thread::factory()->create();
        $first = Post::factory()->inThread($thread)->create(['created_at' => now()->subMinutes(2)]);
        $second = Post::factory()->inThread($thread)->create(['created_at' => now()]);

        $posts = postService()->forThread($thread);

        expect($posts->pluck('id')->all())->toBe([$first->id, $second->id]);
    });

    it('returns only root posts for a thread listing, newest first', function () {
        $thread = Thread::factory()->create();
        $root1 = Post::factory()->inThread($thread)->create(['created_at' => now()->subMinutes(5)]);
        $root2 = Post::factory()->inThread($thread)->create(['created_at' => now()]);
        Post::factory()->replyTo($root1)->create();

        $latest = postService()->latestForThread($thread);

        expect($latest->pluck('id')->all())->toBe([$root2->id, $root1->id]);
    });

    it('respects the limit on latestForThread', function () {
        $thread = Thread::factory()->create();
        Post::factory()->count(5)->inThread($thread)->create();

        expect(postService()->latestForThread($thread, limit: 2))->toHaveCount(2);
    });
});
