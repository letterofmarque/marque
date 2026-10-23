<?php

declare(strict_types=1);

// twentyt's published config/trove.php still set ratio_mode, min_ratio and
// min_seedtime, which trove stopped reading in 2.0.0. Nothing said so, and
// their dashboard judged members against the stale min_ratio while bloodhound
// held the real standing. A stale config must say so, with what's in effect.

use Illuminate\Support\Facades\Log;
use Marque\Trove\TroveServiceProvider;

function reportRemovedTroveConfig(): void
{
    (new TroveServiceProvider(app()))->reportRemovedConfiguration();
}

test('a stale ratio key is reported with the bloodhound key, env var and the value in effect', function () {
    Log::spy();
    config(['trove.min_ratio' => 0.5, 'bloodhound' => ['min_ratio' => 0.75]]);

    reportRemovedTroveConfig();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'trove.min_ratio')
        && str_contains($message, 'bloodhound.min_ratio')
        && str_contains($message, 'BLOODHOUND_MIN_RATIO')
        && str_contains($message, '0.5')
        && str_contains($message, '0.75'));
});

test('every ratio key trove used to hold is reported', function () {
    Log::spy();
    config([
        'trove.ratio_mode' => 'full',
        'trove.min_ratio' => 0.5,
        'trove.min_seedtime' => 86400,
        'bloodhound' => ['ratio_mode' => 'off', 'min_ratio' => 0.5, 'min_seedtime' => 259200],
    ]);

    reportRemovedTroveConfig();

    foreach (['ratio_mode' => '"off"', 'min_ratio' => '0.5', 'min_seedtime' => '259200'] as $key => $effective) {
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, "trove.{$key} ")
            && str_contains($message, "bloodhound.{$key} ")
            && str_contains($message, "which is {$effective}"));
    }
});

test('without bloodhound it says no tracker reads a ratio setting', function () {
    Log::spy();
    config(['trove.ratio_mode' => 'full', 'bloodhound' => null]);

    reportRemovedTroveConfig();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'trove.ratio_mode')
        && str_contains($message, 'no tracker installed here reads a ratio setting'));
});

test('it is reported once a day, not on every boot', function () {
    Log::spy();
    config(['trove.min_ratio' => 0.5]);

    reportRemovedTroveConfig();
    reportRemovedTroveConfig();

    Log::shouldHaveReceived('warning')->once();
});

test('the package\'s own config reports nothing', function () {
    Log::spy();

    reportRemovedTroveConfig();

    Log::shouldNotHaveReceived('warning');
});
