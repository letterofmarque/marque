<?php

declare(strict_types=1);

namespace Marque\Parley\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;

/**
 * Gives any model a discussion.
 *
 *     class Torrent extends Model
 *     {
 *         use HasThreads;
 *     }
 *
 *     $torrent->comments();          // the thread, created on first use
 *     $torrent->commentCount();
 *
 * This trait is what turns "discussion is polymorphic" from an architecture
 * diagram into something a consumer can actually use in one line. Without it,
 * every model wanting comments would hand-roll the same morphMany.
 *
 * @phpstan-require-extends Model
 */
trait HasThreads
{
    /**
     * Every thread attached to this model.
     *
     * @return MorphMany<Thread, $this>
     */
    public function threads(): MorphMany
    {
        return $this->morphMany(Thread::class, 'threadable');
    }

    /**
     * The comment thread for this model, if one exists.
     *
     * Attached models have a single thread in practice — "the comments" — even
     * though the schema allows several, which keeps the door open for things
     * like per-release discussion on one torrent later.
     */
    public function commentThread(): ?Thread
    {
        return $this->threads()->first();
    }

    /**
     * The comment thread, created if this model does not have one yet.
     *
     * Lazily created rather than made alongside the parent record, so a site
     * that never enables comments accumulates no empty threads.
     */
    public function comments(?int $userId = null): Thread
    {
        $thread = $this->commentThread();

        if ($thread !== null) {
            return $thread;
        }

        return $this->threads()->create([
            'user_id' => $userId ?? $this->getAttribute('user_id'),
        ]);
    }

    /**
     * How many posts this model's discussion holds.
     *
     * Counts through the thread rather than off a cached column: a counter
     * column is a denormalisation worth adding when a real deployment shows it
     * is needed, not before.
     */
    public function commentCount(): int
    {
        $thread = $this->commentThread();

        return $thread === null ? 0 : $thread->posts()->count();
    }

    public function hasComments(): bool
    {
        return $this->commentCount() > 0;
    }

    /**
     * The most recent post in this model's discussion.
     */
    public function latestComment(): ?Post
    {
        return $this->commentThread()?->posts()->latest()->first();
    }
}
