<?php

declare(strict_types=1);

namespace Marque\Parley\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Models\Category;
use Marque\Parley\Models\Thread;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Support\StaffAction;

class ThreadService implements ThreadServiceInterface
{
    public function __construct(
        private readonly StaffActionsInterface $staffActions,
    ) {}

    public function create(Authenticatable $user, Category $category, string $title): Thread
    {
        return Thread::create([
            'category_id' => $category->getKey(),
            'title' => $title,
            'user_id' => $user->getAuthIdentifier(),
        ]);
    }

    public function forSubject(Model $subject, Authenticatable $user): Thread
    {
        // The subject decides its own thread shape via HasThreads — the
        // service does not duplicate that logic, it is the entry point a
        // caller reaches it through when going through services rather than
        // the model directly.
        if (! method_exists($subject, 'comments')) {
            throw new \LogicException(sprintf(
                '[%s] does not use the HasThreads trait.',
                $subject::class,
            ));
        }

        return $subject->comments($user->getAuthIdentifier());
    }

    public function threadFor(Model $subject, Authenticatable $user): Thread
    {
        // Same identity HasThreads::comments() resolves through — a subject
        // found via the trait and one found via this method always land on
        // the same row, whichever path a consumer happens to use.
        $thread = Thread::comments()
            ->where('threadable_type', $subject->getMorphClass())
            ->where('threadable_id', $subject->getKey())
            ->first();

        if ($thread !== null) {
            return $thread;
        }

        return Thread::create([
            'threadable_type' => $subject->getMorphClass(),
            'threadable_id' => $subject->getKey(),
            'user_id' => $user->getAuthIdentifier(),
        ]);
    }

    public function update(Thread $thread, string $title, ?Authenticatable $actor = null, ?string $reason = null): Thread
    {
        return DB::transaction(function () use ($thread, $title, $actor, $reason): Thread {
            $from = $thread->title;
            $thread->update(['title' => $title]);

            if ($from !== $title && $this->notTheAuthor($actor, $thread->user_id)) {
                $this->staffActions->record(StaffAction::of($actor, 'parley.thread.edited', $thread, $reason, [
                    'title' => ['from' => $from, 'to' => $title],
                ]));
            }

            return $thread;
        });
    }

    public function delete(Thread $thread, ?Authenticatable $actor = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($thread, $actor, $reason): void {
            $thread->delete();

            if ($this->notTheAuthor($actor, $thread->user_id)) {
                $this->staffActions->record(StaffAction::of($actor, 'parley.thread.deleted', $thread, $reason, [
                    'title' => ['from' => $thread->title, 'to' => null],
                ]));
            }
        });
    }

    public function pin(Thread $thread, ?Authenticatable $actor = null, ?string $reason = null): Thread
    {
        return $this->moderate($thread, 'pinned', true, 'parley.thread.pinned', $actor, $reason);
    }

    public function unpin(Thread $thread, ?Authenticatable $actor = null, ?string $reason = null): Thread
    {
        return $this->moderate($thread, 'pinned', false, 'parley.thread.unpinned', $actor, $reason);
    }

    public function lock(Thread $thread, ?Authenticatable $actor = null, ?string $reason = null): Thread
    {
        return $this->moderate($thread, 'locked', true, 'parley.thread.locked', $actor, $reason);
    }

    public function unlock(Thread $thread, ?Authenticatable $actor = null, ?string $reason = null): Thread
    {
        return $this->moderate($thread, 'locked', false, 'parley.thread.unlocked', $actor, $reason);
    }

    /**
     * Pinning and locking are moderation whoever does them, so they're
     * recorded whenever there's an actor and the flag actually moved.
     */
    protected function moderate(Thread $thread, string $flag, bool $to, string $action, ?Authenticatable $actor, ?string $reason): Thread
    {
        return DB::transaction(function () use ($thread, $flag, $to, $action, $actor, $reason): Thread {
            $from = (bool) $thread->{$flag};
            $thread->update([$flag => $to]);

            if ($actor !== null && $from !== $to) {
                $this->staffActions->record(StaffAction::of($actor, $action, $thread, $reason, [
                    $flag => ['from' => $from, 'to' => $to],
                ]));
            }

            return $thread;
        });
    }

    /**
     * @phpstan-assert-if-true Authenticatable $actor
     */
    protected function notTheAuthor(?Authenticatable $actor, int|string|null $authorId): bool
    {
        return $actor !== null && $actor->getAuthIdentifier() !== $authorId;
    }

    public function paginateForCategory(Category $category, int $perPage = 25): LengthAwarePaginator
    {
        return $category->orderedThreads()->paginate($perPage);
    }

    public function paginateForThreadableType(string $threadableType, int $perPage = 25): LengthAwarePaginator
    {
        return Thread::comments()
            ->where('threadable_type', $threadableType)
            ->latest()
            ->paginate($perPage);
    }
}
