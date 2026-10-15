<?php

declare(strict_types=1);

// Booted with ratio_mode = 'full'. See assertTimeAccruesRegardlessOfRatioMode().

it('accrues seedtime and leechtime under ratio_mode full', function () {
    assertTimeAccruesRegardlessOfRatioMode();
});
