<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Marque\Usarrs\Livewire\Component;

class Show extends Component
{
    public function render(): View
    {
        return $this->usarrsView('usarrs::profile.show', [
            'user' => auth()->user(),
            'hasTrackerStats' => $this->tracker() !== null,
            // Under socialite, where connections are how the account is reached
            // (#10858). The route exists only then.
            'showsConnectedAccounts' => Route::has('socialite.connect'),
        ])->title(__('Profile'));
    }
}
