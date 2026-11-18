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
            // It sends the member to confirm it's them, a page usarrs has only
            // when it manages auth (#11154). With manage_auth off, the app
            // calls SessionEpoch::endOthers() from its own UI.
            'showsOtherSessions' => Route::has('password.confirm') && config('usarrs.manage_auth', true),
        ])->title(__('Profile'));
    }
}
