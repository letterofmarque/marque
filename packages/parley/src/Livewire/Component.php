<?php

declare(strict_types=1);

namespace Marque\Parley\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component as LivewireComponent;

/**
 * Same shape as guise's and usarrs's own base component — a layout pulled
 * from config rather than hard-coded, so a consumer publishing parley's views
 * can point them at their own shell.
 */
abstract class Component extends LivewireComponent
{
    protected function parleyLayout(): string
    {
        return config('parley.layout', 'ise::layouts.app');
    }

    protected function parleyView(string $view, array $data = []): View
    {
        return view($view, $data)->layout($this->parleyLayout());
    }
}
