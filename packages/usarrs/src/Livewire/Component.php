<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component as LivewireComponent;

abstract class Component extends LivewireComponent
{
    protected function usarrsLayout(): string
    {
        return config('usarrs.layout', 'id::layouts.app');
    }

    protected function usarrsView(string $view, array $data = []): View
    {
        return view($view, $data)->layout($this->usarrsLayout());
    }
}
