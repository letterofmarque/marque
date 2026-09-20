<?php

declare(strict_types=1);

// Spec #118 criterion 3 and OQ6. On seedtime the ratio panel is also wrong:
// a different question, measured in different units. The seedtime panel that
// would answer it is deliberately out of scope for v1 — nothing renders seed
// time anywhere today, so it is new work rather than assembly.
//
// A seedtime tracker's dashboard is therefore thinner, but never WRONG, which
// is the property that matters.

use Marque\Trove\Registry\DashboardPanelRegistry;

test('the ratio panel does NOT register when ratio_mode is seedtime', function () {
    expect(app(DashboardPanelRegistry::class)->find('bloodhound-ratio'))->toBeNull();
})->todo('Spec #119: panels register once the tracker-stats contract lands');

test('the announce key panel still registers', function () {
    expect(app(DashboardPanelRegistry::class)->find('bloodhound-announce-key'))->not->toBeNull();
})->todo('Spec #119: panels register once the tracker-stats contract lands');
