<?php

declare(strict_types=1);

namespace Marque\Parley\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Marque\Parley\Exceptions\ThreadLockedException;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;

interface PostServiceInterface
{
    /**
     * @throws ThreadLockedException if the thread is locked
     */
    public function create(Thread $thread, Authenticatable $user, string $body, ?string $format = null): Post;

    /**
     * @throws ThreadLockedException if the thread is locked
     */
    public function reply(Post $parent, Authenticatable $user, string $body, ?string $format = null): Post;

    public function update(Post $post, string $body, ?string $format = null): Post;

    /**
     * Soft-deletes the post. Replies are NOT cascaded — a reply chain
     * surviving its parent's removal is the point of reply_to_id nulling on
     * delete rather than cascading, see the posts migration.
     */
    public function delete(Post $post): void;

    /**
     * The whole thread's posts, ordered for a caller that wants to build the
     * nested tree itself. Loading everything at once is deliberate — the spec
     * settled on no pagination within a thread (private-tracker desktop users),
     * so a subtree loads whole rather than in pages.
     */
    public function forThread(Thread $thread): Collection;

    /**
     * A page of the top-level threads a listing shows, most-recently-active
     * first. This is the paginated boundary — individual threads are not.
     */
    public function latestForThread(Thread $thread, int $limit = 50): Collection;
}
