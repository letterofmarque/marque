<?php

declare(strict_types=1);

namespace Marque\Parley\Livewire\Forum;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\WithPagination;
use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Livewire\Component as ParleyComponent;
use Marque\Parley\Models\Category;

/**
 * Threads within one category, pinned first then newest — the ordering
 * ThreadService::paginateForCategory() and Thread::scopeOrdered() both
 * implement, kept in one place so the forum listing and any future admin
 * tooling agree on it.
 */
#[Title('Forum')]
class ThreadIndex extends ParleyComponent
{
    use WithPagination;

    public Category $category;

    public function mount(Category $category): void
    {
        $this->category = $category;
    }

    public function render(ThreadServiceInterface $threads): View
    {
        return $this->parleyView('parley::forum.thread-index', [
            'category' => $this->category,
            'threads' => $threads->paginateForCategory(
                $this->category,
                perPage: config('parley.loading.threads', 25),
            ),
        ])->title($this->category->name);
    }
}
