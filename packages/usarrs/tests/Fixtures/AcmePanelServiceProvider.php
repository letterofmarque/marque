<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;
use Marque\Trove\Registry\DashboardPanel;
use Marque\Trove\Registry\DashboardPanelRegistry;

/**
 * Stands in for a package Marque has never heard of: it registers its own
 * Livewire component and a panel naming it, from its own boot(), against
 * trove's registry. It references nothing in usarrs, and usarrs references
 * nothing in it — that mutual ignorance is the seam (Spec #118 criterion 5).
 */
class AcmePanelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('acme-stats-panel', AcmeStatsPanel::class);

        $this->app->make(DashboardPanelRegistry::class)->register(new DashboardPanel(
            identifier: 'acme-stats',
            label: 'Acme Stats',
            component: 'acme-stats-panel',
            // Between usarrs' security (30) and invites (40) panels, so the
            // test can see it ordered among first-party panels rather than
            // appended after them.
            position: 35,
        ));
    }
}

class AcmeStatsPanel extends Component
{
    public function render(): string
    {
        return '<div>42 widgets frobbed</div>';
    }
}
