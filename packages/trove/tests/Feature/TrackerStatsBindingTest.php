<?php

declare(strict_types=1);

use Marque\Trove\Contracts\TrackerStatsInterface;

// trove declares the contract and must never fulfil it. Absence of a tracker
// is signalled by absence of a binding — a null-object default registered
// here would make app()->bound() true on every install and turn "there is no
// tracker" into "there is a tracker whose figures are all zero" (Spec #119).
it('does not bind TrackerStatsInterface on its own', function () {
    expect(app()->bound(TrackerStatsInterface::class))->toBeFalse();
});
