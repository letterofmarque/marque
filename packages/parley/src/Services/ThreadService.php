<?php

declare(strict_types=1);

namespace Marque\Parley\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Models\Category;
use Marque\Parley\Models\Thread;

class ThreadService implements ThreadServiceInterface
{
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

    public function update(Thread $thread, string $title): Thread
    {
        $thread->update(['title' => $title]);

        return $thread;
    }

    public function delete(Thread $thread): void
    {
        $thread->delete();
    }

    public function pin(Thread $thread): Thread
    {
        $thread->update(['pinned' => true]);

        return $thread;
    }

    public function unpin(Thread $thread): Thread
    {
        $thread->update(['pinned' => false]);

        return $thread;
    }

    public function lock(Thread $thread): Thread
    {
        $thread->update(['locked' => true]);

        return $thread;
    }

    public function unlock(Thread $thread): Thread
    {
        $thread->update(['locked' => false]);

        return $thread;
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
