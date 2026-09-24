<?php

declare(strict_types=1);

// Booted with ratio_mode = 'full'. See assertStatsReportedRegardlessOfRatioMode().

it('reports stored figures under ratio_mode full', function () {
    assertStatsReportedRegardlessOfRatioMode();
});
