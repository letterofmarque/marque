<?php

declare(strict_types=1);

// Booted with ratio_mode = 'seedtime'. See assertStatsReportedRegardlessOfRatioMode().

it('reports stored figures under ratio_mode seedtime', function () {
    assertStatsReportedRegardlessOfRatioMode();
});
