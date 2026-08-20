<?php

declare(strict_types=1);

namespace Marque\Parley\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Marque\Parley\Models\Category;
use Marque\Parley\Models\Thread;

interface ThreadServiceInterface
{
    /**
     * A standalone forum thread — titled, categorised.
     */
    public function create(Authenticatable $user, Category $category, string $title): Thread;

    /**
     * The comment thread for an arbitrary model, created if it does not exist
     * yet. Thin wrapper over HasThreads::comments() at the service layer, so
     * callers that go through services rather than the model directly still
     * get lazy creation.
     *
     * @throws \LogicException if $subject does not use HasThreads — use
     *                          threadFor() instead for a model that can't
     *                          take the trait (see docs/integration.md).
     */
    public function forSubject(Model $subject, Authenticatable $user): Thread;

    /**
     * The comment thread for an arbitrary model, created if it does not exist
     * yet — without requiring HasThreads on the model.
     *
     * This is the entry point for a consumer that cannot add HasThreads to the
     * subject itself, typically because the subject is owned by a mandatory
     * package that can't take a dependency on parley (docs/integration.md,
     * "attaching to a model you don't own"). It works against the subject's
     * morph class and key directly, the same identity HasThreads::comments()
     * resolves through — the two paths always find the same thread.
     */
    public function threadFor(Model $subject, Authenticatable $user): Thread;

    public function update(Thread $thread, string $title): Thread;

    /**
     * Soft-deletes the thread. Its posts are left as-is — they soft-delete
     * with it structurally, but are not force-deleted, matching the "do not
     * destroy other people's words" rule that governs post deletion too.
     */
    public function delete(Thread $thread): void;

    public function pin(Thread $thread): Thread;

    public function unpin(Thread $thread): Thread;

    public function lock(Thread $thread): Thread;

    public function unlock(Thread $thread): Thread;

    /**
     * Forum threads in a category, pinned first then newest.
     */
    public function paginateForCategory(Category $category, int $perPage = 25): LengthAwarePaginator;

    /**
     * All comment threads attached to a given class of subject — mainly a
     * moderation/admin tool, not a reader-facing listing.
     */
    public function paginateForThreadableType(string $threadableType, int $perPage = 25): LengthAwarePaginator;
}
