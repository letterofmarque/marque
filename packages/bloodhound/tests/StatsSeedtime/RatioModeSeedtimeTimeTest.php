<?php

declare(strict_types=1);

// Booted with ratio_mode = 'seedtime'. See assertTimeAccruesRegardlessOfRatioMode().

it('accrues seedtime and leechtime under ratio_mode seedtime', function () {
    assertTimeAccruesRegardlessOfRatioMode();
});
