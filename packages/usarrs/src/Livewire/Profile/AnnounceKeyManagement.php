<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Marque\Usarrs\Livewire\Component;

class AnnounceKeyManagement extends Component
{
    public function regenerateAnnounceKey(): void
    {
        $user = auth()->user();

        abort_unless(config('usarrs.profile.allow_announce_key_regen', true), 403);
        abort_unless(method_exists($user, 'generateAnnounceKey'), 404);

        $user->update(['announce_key' => $user->generateAnnounceKey()]);

        session()->flash('status', __('Announce key regenerated.'));
    }

    public function render(): View
    {
        $user = auth()->user();

        return $this->usarrsView('usarrs::profile.stats', [
            'user' => $user,
            'hasTrackerStats' => method_exists($user, 'getRatio'),
            'showAnnounceKey' => config('usarrs.profile.show_announce_key', true) && isset($user->announce_key),
            'allowRegen' => config('usarrs.profile.allow_announce_key_regen', true),
        ])->title(__('Tracker Stats'));
    }
}
