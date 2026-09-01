<?php

declare(strict_types=1);

use Marque\Parley\Contracts\PostServiceInterface;
use Marque\Parley\Exceptions\ThreadLockedException;
use Marque\Parley\Exceptions\TooManyPostsException;
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

    it('does NOT reject an update on a locked thread by default — editing is not posting', function () {
        // config('parley.moderation.lock_blocks_edits') defaults to false:
        // locking stops new posts and replies, not edits to what's already
        // there. See PostServiceTest > "lock_blocks_edits" below for the
        // site-owner-opted-in behaviour.
        $thread = Thread::factory()->create();
        $user = TestUser::factory()->create();
        $post = postService()->create($thread, $user, 'original');

        $thread->update(['locked' => true]);

        $updated = postService()->update($post, 'edited');

        expect($updated->body)->toBe('edited');
    });

    it('does NOT reject a delete on a locked thread by default', function () {
        $thread = Thread::factory()->create();
        $post = Post::factory()->inThread($thread)->create();

        $thread->update(['locked' => true]);

        postService()->delete($post);

        expect(Post::find($post->id))->toBeNull();
    });

    describe('with lock_blocks_edits enabled', function () {
        beforeEach(function () {
            config(['parley.moderation.lock_blocks_edits' => true]);
        });

        it('refuses an update once the thread is locked', function () {
            $thread = Thread::factory()->create();
            $user = TestUser::factory()->create();
            $post = postService()->create($thread, $user, 'original');

            $thread->update(['locked' => true]);

            expect(fn () => postService()->update($post, 'edited'))
                ->toThrow(ThreadLockedException::class);

            expect($post->fresh()->body)->toBe('original');
        });

        it('refuses a delete once the thread is locked', function () {
            $thread = Thread::factory()->create();
            $post = Post::factory()->inThread($thread)->create();

            $thread->update(['locked' => true]);

            expect(fn () => postService()->delete($post))
                ->toThrow(ThreadLockedException::class);

            expect(Post::find($post->id))->not->toBeNull();
        });

        it('still allows editing and deleting while the thread is unlocked', function () {
            $thread = Thread::factory()->create();
            $user = TestUser::factory()->create();
            $post = postService()->create($thread, $user, 'original');

            $updated = postService()->update($post, 'edited');
            expect($updated->body)->toBe('edited');

            postService()->delete($post);
            expect(Post::find($post->id))->toBeNull();
        });
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

    describe('rate limiting', function () {
        it('does not throttle when disabled — the default', function () {
            // config('parley.rate_limiting.enabled') defaults to false, so
            // RateLimiter is never consulted at all — posting stays unlimited
            // until a site owner opts in. See Spec #94's "off by default"
            // Decision: an upgrade to parley must not silently start
            // rejecting a legitimate power-user's fast-typing session.
            $thread = Thread::factory()->create();
            $user = TestUser::factory()->create();

            for ($i = 0; $i < 10; $i++) {
                postService()->create($thread, $user, "post $i");
            }

            expect($thread->posts)->toHaveCount(10);
        });

        describe('with rate_limiting enabled', function () {
            beforeEach(function () {
                config([
                    'parley.rate_limiting.enabled' => true,
                    'parley.rate_limiting.max_attempts' => 3,
                    'parley.rate_limiting.decay_seconds' => 60,
                ]);
            });

            it('allows posting up to the configured limit', function () {
                $thread = Thread::factory()->create();
                $user = TestUser::factory()->create();

                for ($i = 0; $i < 3; $i++) {
                    postService()->create($thread, $user, "post $i");
                }

                expect($thread->posts)->toHaveCount(3);
            });

            it('refuses the post past the limit within the window', function () {
                $thread = Thread::factory()->create();
                $user = TestUser::factory()->create();

                for ($i = 0; $i < 3; $i++) {
                    postService()->create($thread, $user, "post $i");
                }

                expect(fn () => postService()->create($thread, $user, 'one too many'))
                    ->toThrow(TooManyPostsException::class);

                expect($thread->posts)->toHaveCount(3);
            });

            it('throttles replies using the same per-user limit as new posts', function () {
                $thread = Thread::factory()->create();
                $user = TestUser::factory()->create();
                $root = postService()->create($thread, $user, 'root');

                // root already consumed one attempt — two more replies reach
                // the limit of 3, the fourth call throws.
                postService()->reply($root, $user, 'reply 1');
                postService()->reply($root, $user, 'reply 2');

                expect(fn () => postService()->reply($root, $user, 'reply 3'))
                    ->toThrow(TooManyPostsException::class);
            });

            it('keys the limit per-user — one user throttled does not affect another', function () {
                $thread = Thread::factory()->create();
                $flooder = TestUser::factory()->create();
                $otherUser = TestUser::factory()->create();

                for ($i = 0; $i < 3; $i++) {
                    postService()->create($thread, $flooder, "post $i");
                }

                expect(fn () => postService()->create($thread, $flooder, 'blocked'))
                    ->toThrow(TooManyPostsException::class);

                // A different user has consumed none of their own limit yet.
                $post = postService()->create($thread, $otherUser, 'still fine');
                expect($post->user_id)->toBe($otherUser->id);
            });
        });
    });
});
