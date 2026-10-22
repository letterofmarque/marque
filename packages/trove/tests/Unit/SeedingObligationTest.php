<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Marque\Trove\Enums\ObligationState;
use Marque\Trove\Support\SeedingObligation;

// Job #256: a torrent a member owes seeding on, as the tracker's hit-and-run
// rule sees it. Met by time, or by ratio on the torrent where the tracker
// accepts one, whichever comes first.

function obligation(int $seeded = 0, ?float $ratio = 0.2, ?float $requiredRatio = 1.0, ObligationState $state = ObligationState::Open, ?Carbon $until = null): SeedingObligation
{
    return new SeedingObligation(
        torrentId: 7,
        torrentName: 'Big Buck Bunny',
        seededSeconds: $seeded,
        requiredSeconds: 72 * 3600,
        ratio: $ratio,
        requiredRatio: $requiredRatio,
        state: $state,
        until: $until,
    );
}

it('is unmet while short of both the time and the ratio', function () {
    $o = obligation(seeded: 31 * 3600, ratio: 0.4);

    expect($o->met())->toBeFalse()
        ->and($o->remainingSeconds())->toBe(41 * 3600);
});

it('is met by seeding the required time', function () {
    expect(obligation(seeded: 72 * 3600, ratio: 0.1)->met())->toBeTrue()
        ->and(obligation(seeded: 72 * 3600)->remainingSeconds())->toBe(0);
});

it('is met by reaching the ratio on the torrent, when the tracker accepts one', function () {
    expect(obligation(seeded: 60, ratio: 1.0)->met())->toBeTrue();
});

it('is not met by ratio when the tracker accepts time only', function () {
    expect(obligation(seeded: 60, ratio: 3.0, requiredRatio: null)->met())->toBeFalse();
});

it('treats an infinite ratio (nothing downloaded) as meeting a ratio requirement', function () {
    expect(obligation(seeded: 0, ratio: null)->met())->toBeTrue();
});

it('carries its state and, for a strike, when it lapses', function () {
    $until = Carbon::parse('2026-11-05 10:00:00');
    $o = obligation(state: ObligationState::Struck, until: $until);

    expect($o->state)->toBe(ObligationState::Struck)
        ->and($o->until?->equalTo($until))->toBeTrue()
        ->and($o->torrentName)->toBe('Big Buck Bunny');
});

it('refuses negative seconds', function () {
    obligation(seeded: -1);
})->throws(InvalidArgumentException::class);
