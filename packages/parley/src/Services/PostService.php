<?php

declare(strict_types=1);

namespace Marque\Parley\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Marque\Parley\Contracts\PostServiceInterface;
use Marque\Parley\Exceptions\ThreadLockedException;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;

class PostService implements PostServiceInterface
{
    public function create(Thread $thread, Authenticatable $user, string $body, ?string $format = null): Post
    {
        $this->assertUnlocked($thread);

        return Post::create([
            'thread_id' => $thread->getKey(),
            'user_id' => $user->getAuthIdentifier(),
            'body' => $body,
            'body_format' => $format,
        ]);
    }

    public function reply(Post $parent, Authenticatable $user, string $body, ?string $format = null): Post
    {
        // Loaded rather than assumed present, so a caller handed a parent post
        // without its thread relation still gets the lock checked correctly.
        $thread = $parent->thread()->firstOrFail();

        $this->assertUnlocked($thread);

        return Post::create([
            'thread_id' => $thread->getKey(),
            'user_id' => $user->getAuthIdentifier(),
            'body' => $body,
            'body_format' => $format,
            'reply_to_id' => $parent->getKey(),
        ]);
    }

    public function update(Post $post, string $body, ?string $format = null): Post
    {
        $post->update(array_filter([
            'body' => $body,
            'body_format' => $format,
        ], static fn ($value): bool => $value !== null));

        return $post;
    }

    public function delete(Post $post): void
    {
        // Soft delete only. reply_to_id on any children nulls via the FK's
        // ON DELETE SET NULL when the row is force-deleted, but a soft delete
        // leaves the row (and the FK) in place — children keep pointing at a
        // trashed parent, which the renderer can still show as "[deleted]".
        $post->delete();
    }

    public function forThread(Thread $thread): Collection
    {
        return $thread->posts()->with('user')->oldest()->get();
    }

    public function latestForThread(Thread $thread, int $limit = 50): Collection
    {
        return $thread->rootPosts()->with('user')->latest()->limit($limit)->get();
    }

    /**
     * @throws ThreadLockedException
     */
    private function assertUnlocked(Thread $thread): void
    {
        if ($thread->locked) {
            throw ThreadLockedException::forThread($thread);
        }
    }
}
