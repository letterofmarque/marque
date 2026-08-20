<?php

declare(strict_types=1);

namespace Marque\Parley\Livewire\Forum;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Marque\Parley\Livewire\Component as ParleyComponent;
use Marque\Parley\Models\Category;

/**
 * The forum's front page: every category, in display order.
 *
 * Categories are admin-managed and few — unlike threads, there is no
 * pagination here. A deployment with dozens of categories is not the case
 * this component is built for; see Spec #79's bounded scope.
 */
#[Title('Forum')]
class CategoryIndex extends ParleyComponent
{
    public function render(): View
    {
        return $this->parleyView('parley::forum.category-index', [
            'categories' => Category::query()
                ->withCount('threads')
                ->orderBy('position')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
