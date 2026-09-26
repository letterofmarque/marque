<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Dashboard;

use Illuminate\Contracts\View\View;
use Marque\Trove\Contracts\UserInterface;
use Marque\Usarrs\Livewire\Profile\AnnounceKeyManagement;

/**
 * The dashboard's announce key panel.
 *
 * Extends the /profile/stats component rather than copying it, so regenerate
 * is the same action in both places — the same config gate, the same 404 with
 * no tracker, the same round-trip through TrackerStatsInterface. Only the view
 * differs: a panel, not a page.
 */
class AnnounceKeyPanel extends AnnounceKeyManagement
{
    public function render(): View
    {
        $user = auth()->user();

        return view('usarrs::dashboard.panels.announce-key', [
            'announceKey' => $user instanceof UserInterface ? $this->tracker()?->announceKeyFor($user) : null,
            'allowRegen' => config('usarrs.profile.allow_announce_key_regen', true),
        ]);
    }
}
