<?php

declare(strict_types=1);

// Booted with ratio_mode = 'off'. See assertTimeAccruesRegardlessOfRatioMode().

it('accrues seedtime and leechtime under ratio_mode off', function () {
    assertTimeAccruesRegardlessOfRatioMode();
});
