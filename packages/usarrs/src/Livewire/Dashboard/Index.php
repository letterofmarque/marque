<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Dashboard;

use Illuminate\Contracts\View\View;
use Marque\Trove\Registry\DashboardPanelRegistry;
use Marque\Usarrs\Livewire\Component;

/**
 * The user dashboard — one screen answering "how am I doing".
 *
 * usarrs owns the page and almost none of its content. Ratio and announce key
 * belong to bloodhound; other packages may contribute anything. This component
 * asks trove's registry what to render and renders it, and deliberately names
 * no other package (Spec #118).
 */
class Index extends Component
{
    public function render(DashboardPanelRegistry $panels): View
    {
        return $this->usarrsView('usarrs::dashboard.index', [
            // Filtered per request against the current user, so a panel whose
            // closure declines is absent rather than rendered-and-hidden.
            'panels' => $panels->visibleTo(auth()->user()),
        ])->title(__('Dashboard'));
    }
}
