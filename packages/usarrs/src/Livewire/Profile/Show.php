<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Marque\Usarrs\Livewire\Component;

class Show extends Component
{
    public function render(): View
    {
        return $this->usarrsView('usarrs::profile.show', [
            'user' => auth()->user(),
            'hasTrackerStats' => $this->tracker() !== null,
        ])->title(__('Profile'));
    }
}
