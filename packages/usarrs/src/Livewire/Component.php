<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component as LivewireComponent;
use Marque\Trove\Contracts\TrackerStatsInterface;

abstract class Component extends LivewireComponent
{
    /**
     * The installed tracker's stats, or null if there is no tracker.
     *
     * usarrs does not require a tracker, so it asks rather than assumes: the
     * binding exists only when one (bloodhound) is installed. Never probe the
     * User model for tracker columns or methods instead — that answers "is a
     * trait applied", not "is there a tracker" (Spec #119).
     */
    protected function tracker(): ?TrackerStatsInterface
    {
        return app()->bound(TrackerStatsInterface::class)
            ? app(TrackerStatsInterface::class)
            : null;
    }

    protected function usarrsLayout(): string
    {
        return config('usarrs.layout', 'deck::layouts.app');
    }

    protected function usarrsView(string $view, array $data = []): View
    {
        return view($view, $data)->layout($this->usarrsLayout());
    }
}
