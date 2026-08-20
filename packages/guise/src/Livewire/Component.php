<?php

declare(strict_types=1);

namespace Marque\Guise\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component as LivewireComponent;

abstract class Component extends LivewireComponent
{
    /**
     * Get the layout for this component from config.
     */
    protected function guiseLayout(): string
    {
        return config('guise.layout', 'ise::layouts.app');
    }

    /**
     * Wrap a view with the configured layout.
     */
    protected function guiseView(string $view, array $data = []): View
    {
        return view($view, $data)->layout($this->guiseLayout());
    }
}
