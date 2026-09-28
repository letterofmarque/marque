<?php

declare(strict_types=1);

namespace Marque\Trove\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Marque\Trove\Registry\DashboardPanel;
use Marque\Trove\Registry\DashboardPanelRegistry;

/**
 * Stands in for a package Marque has never heard of, contributing a dashboard
 * panel the way the README tells one to: from its own boot(), against trove's
 * registry, and nothing else.
 *
 * It imports trove and Laravel only. No usarrs, no Livewire, no reference to
 * the page that might render it — a panel is a declaration, and making one
 * must not require the dashboard to be installed (Spec #118 criterion 5).
 */
class AcmePanelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(DashboardPanelRegistry::class)->register(new DashboardPanel(
            identifier: 'acme-stats',
            label: 'Acme Stats',
            component: 'acme-stats-panel',
            position: 35,
            visible: fn (?object $user): bool => $user !== null,
        ));
    }
}
