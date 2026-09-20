<?php

declare(strict_types=1);

// Spec #118 criterion 3, and the case that motivated the whole capability rule.
//
// A login-required no-ratio tracker (bloodhound + guise + usarrs with
// ratio_mode=off) has bloodhound INSTALLED while enforcing no ratio and
// tracking no bytes. A panel keyed on "is bloodhound present?" would show such
// a user a ratio their operator does not keep.

use Marque\Trove\Registry\DashboardPanelRegistry;

test('the ratio panel does NOT register when ratio_mode is off', function () {
    expect(app(DashboardPanelRegistry::class)->find('bloodhound-ratio'))->toBeNull();
})->todo('Spec #119: panels register once the tracker-stats contract lands');

test('the announce key panel still registers', function () {
    // The key is how the tracker identifies the announcing user. That matters
    // in every private-tracker shape, ratio or no ratio.
    expect(app(DashboardPanelRegistry::class)->find('bloodhound-announce-key'))->not->toBeNull();
})->todo('Spec #119: panels register once the tracker-stats contract lands');
