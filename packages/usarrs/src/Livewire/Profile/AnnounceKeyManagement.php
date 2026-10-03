<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Marque\Trove\Contracts\UserInterface;
use Marque\Usarrs\Auth\VerifiedAddress;
use Marque\Usarrs\Livewire\Component;

class AnnounceKeyManagement extends Component
{
    public function regenerateAnnounceKey(): void
    {
        $user = auth()->user();
        $tracker = $this->tracker();

        abort_unless($tracker !== null && $user instanceof UserInterface, 404);

        // Regeneration can be switched off; a first key can't, or a user who
        // has none would have no way to get one.
        abort_unless(config('usarrs.profile.allow_announce_key_regen', true) || $tracker->announceKeyFor($user) === null, 403);

        // No key for an address nobody has proven (#10879). bloodhound holds
        // one back at sign-up for the same reason and issues it on
        // verification; this is the button.
        if (VerifiedAddress::missing($user)) {
            $this->addError('email', __('Verify your email address to get an announce key.'));

            return;
        }

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
            'showAnnounceKey' => config('usarrs.profile.show_announce_key', true) && $tracker !== null,
            'allowRegen' => config('usarrs.profile.allow_announce_key_regen', true),
            'addressUnproven' => VerifiedAddress::missing($user),
        ])->title(__('Tracker Stats'));
    }
}
