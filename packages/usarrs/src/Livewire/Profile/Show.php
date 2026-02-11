<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Marque\Usarrs\Livewire\Component;

class Show extends Component
{
    public function render(): View
    {
        $user = auth()->user();
        $hasTrackerStats = method_exists($user, 'getRatio');

        return $this->usarrsView('usarrs::profile.show', [
            'user' => $user,
            'hasTrackerStats' => $hasTrackerStats,
        ])->title(__('Profile'));
    }
}
