<?php

declare(strict_types=1);

// Spec #118 criterion 4, as amended at Build #108 CP3: bloodhound contributes
// no dashboard panels and has no view layer. Its figures reach the dashboard
// through trove's TrackerStatsInterface, rendered by whoever renders panels
// (usarrs today). A panel registered here would mean bloodhound growing
// presentation, which was tried and reverted on 2026-09-18.
//
// This suite boots bloodhound without usarrs, so it also stands as the
// criterion 5 check for a tracker: nothing here needs a dashboard to exist.

use Marque\Trove\Registry\DashboardPanelRegistry;

it('boots without usarrs installed', function () {
    expect(class_exists('Marque\\Usarrs\\UsarrsServiceProvider'))->toBeFalse();
});

it('registers no dashboard panels', function () {
    expect(app(DashboardPanelRegistry::class)->all())->toBeEmpty();
});
