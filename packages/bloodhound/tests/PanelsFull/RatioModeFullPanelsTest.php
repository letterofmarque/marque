<?php

declare(strict_types=1);

// Spec #118 criterion 3, the affirmative half. With ratio enforced, bloodhound
// contributes the ratio panel — it owns those numbers, so it owns their
// presentation, and usarrs never names them.

use Marque\Trove\Registry\DashboardPanelRegistry;

test('the ratio panel registers when ratio_mode is full', function () {
    expect(app(DashboardPanelRegistry::class)->find('bloodhound-ratio'))->not->toBeNull();
})->todo('Spec #119: panels register once the tracker-stats contract lands');

test('the announce key panel registers too', function () {
    expect(app(DashboardPanelRegistry::class)->find('bloodhound-announce-key'))->not->toBeNull();
})->todo('Spec #119: panels register once the tracker-stats contract lands');

test('the ratio panel names a bloodhound component, not a usarrs one', function () {
    // Criterion 4: bloodhound renders its own data. Pointing at usarrs'
    // AnnounceKeyManagement would be a cross-package reference in the wrong
    // direction — usarrs is optional and bloodhound must not require it.
    $panel = app(DashboardPanelRegistry::class)->find('bloodhound-ratio');

    expect($panel->component)->toStartWith('bloodhound-');
})->todo('Spec #119: panels register once the tracker-stats contract lands');
