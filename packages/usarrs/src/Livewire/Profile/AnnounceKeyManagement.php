<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Marque\Trove\Contracts\UserInterface;
use Marque\Usarrs\Livewire\Component;

class AnnounceKeyManagement extends Component
{
    public function regenerateAnnounceKey(): void
    {
        $user = auth()->user();
        $tracker = $this->tracker();

        abort_unless(config('usarrs.profile.allow_announce_key_regen', true), 403);
        abort_unless($tracker !== null && $user instanceof UserInterface, 404);

        // The tracker owns announce keys and is the only thing that writes
        // them; usarrs asks it to rather than setting a column on the model.
        $tracker->regenerateAnnounceKey($user);

        session()->flash('status', __('Announce key regenerated.'));
    }

    public function render(): View
    {
        $user = auth()->user();
        $tracker = $user instanceof UserInterface ? $this->tracker() : null;
        $announceKey = $tracker?->announceKeyFor($user);

        return $this->usarrsView('usarrs::profile.stats', [
            'stats' => $tracker?->statsFor($user),
            'announceKey' => $announceKey,
            'showAnnounceKey' => config('usarrs.profile.show_announce_key', true) && $announceKey !== null,
            'allowRegen' => config('usarrs.profile.allow_announce_key_regen', true),
        ])->title(__('Tracker Stats'));
    }
}
