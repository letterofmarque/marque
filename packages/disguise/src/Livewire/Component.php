<?php

declare(strict_types=1);

namespace Marque\Disguise\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component as LivewireComponent;

abstract class Component extends LivewireComponent
{
    protected function disguiseLayout(): string
    {
        return config('disguise.layout', 'ise::layouts.app');
    }

    protected function disguiseView(string $view, array $data = []): View
    {
        return view($view, $data)->layout($this->disguiseLayout());
    }
}
