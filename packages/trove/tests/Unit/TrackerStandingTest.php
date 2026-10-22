<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Marque\Trove\Enums\StandingState;
use Marque\Trove\Support\TrackerStanding;
use Marque\Trove\Support\TrackerStats;

// #10732: where a member stands against the tracker's ratio rule, reported
// beside the figures it was judged on, so a dashboard can say "on watch until
// Friday" without knowing which tracker decided it.
describe('TrackerStanding', function () {
    it('is ok, with no deadline and no reason', function () {
        $standing = TrackerStanding::ok();

        expect($standing->state)->toBe(StandingState::Ok)
            ->and($standing->until)->toBeNull()
            ->and($standing->reason)->toBeNull()
            ->and($standing->isRestricted())->toBeFalse();
    });

    it('carries the deadline of a watch', function () {
        $deadline = Carbon::parse('2026-11-05 12:00:00');
        $standing = TrackerStanding::watch($deadline, 'Your ratio is 0.31; it needs to be 0.5.');

        expect($standing->state)->toBe(StandingState::Watch)
            ->and($standing->until?->equalTo($deadline))->toBeTrue()
            ->and($standing->reason)->toBe('Your ratio is 0.31; it needs to be 0.5.')
            ->and($standing->isRestricted())->toBeFalse();
    });

    it('carries the reason for a restriction, and says it is one', function () {
        $standing = TrackerStanding::restricted('Downloading is paused until your ratio reaches 0.5.');

        expect($standing->state)->toBe(StandingState::Restricted)
            ->and($standing->reason)->toBe('Downloading is paused until your ratio reaches 0.5.')
            ->and($standing->isRestricted())->toBeTrue();
    });

    it('can be exempt', function () {
        expect(TrackerStanding::exempt()->state)->toBe(StandingState::Exempt)
            ->and(TrackerStanding::exempt()->isRestricted())->toBeFalse();
    });
});

describe('TrackerStats with a standing', function () {
    it('is ok when the tracker reports none, so older trackers still construct it', function () {
        expect((new TrackerStats(uploaded: 0, downloaded: 0, seedtime: 0))->standing->state)->toBe(StandingState::Ok);
    });

    it('carries the standing it was given', function () {
        $stats = new TrackerStats(uploaded: 0, downloaded: 0, seedtime: 0, standing: TrackerStanding::restricted('Paused.'));

        expect($stats->standing->isRestricted())->toBeTrue();
    });
});
