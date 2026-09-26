<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Dashboard;

use Illuminate\Contracts\View\View;
use Marque\Trove\Contracts\UserInterface;
use Marque\Usarrs\Livewire\Component;

/**
 * The dashboard's ratio panel: uploaded, downloaded and ratio, as the tracker
 * reports them.
 *
 * Registered only when a tracker is bound, and visible only when it keeps
 * figures for this user — so by the time this renders, both are true. The
 * null checks below are for a panel mounted somewhere else, not for the
 * dashboard.
 */
class TrackerStatsPanel extends Component
{
    public function render(): View
    {
        $user = auth()->user();

        return view('usarrs::dashboard.panels.tracker-stats', [
            'stats' => $user instanceof UserInterface ? $this->tracker()?->statsFor($user) : null,
        ]);
    }
}
