<?php

declare(strict_types=1);

// Booted with ratio_mode = 'off'. See assertStatsReportedRegardlessOfRatioMode().

it('reports stored figures under ratio_mode off', function () {
    assertStatsReportedRegardlessOfRatioMode();
});
