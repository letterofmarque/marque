<?php

declare(strict_types=1);

use Marque\Trove\Support\TrackerStats;

describe('TrackerStats', function () {
    it('carries the raw figures it was given', function () {
        $stats = new TrackerStats(uploaded: 3_000, downloaded: 2_000, seedtime: 86_400);

        expect($stats->uploaded)->toBe(3_000)
            ->and($stats->downloaded)->toBe(2_000)
            ->and($stats->seedtime)->toBe(86_400);
    });

    // Raw, not rounded: rounding is presentation, and the contract leaves
    // presentation to the consumer (Spec #119, "raw integers, not formatted strings").
    it('computes the ratio unrounded', function () {
        $stats = new TrackerStats(uploaded: 1_000, downloaded: 3_000, seedtime: 0);

        expect($stats->ratio)->toBe(1_000 / 3_000);
    });

    // The case the Spec calls out by name: null here means INFINITE, not
    // unknown. A consumer rendering it as 0.00 tells a user with a perfect
    // ratio that they are in trouble.
    it('reports an infinite ratio as null when nothing has been downloaded', function () {
        $stats = new TrackerStats(uploaded: 5_000, downloaded: 0, seedtime: 0);

        expect($stats->ratio)->toBeNull()
            ->and($stats->hasInfiniteRatio())->toBeTrue();
    });

    // Matches bloodhound's getRatio(): a user who has done nothing yet has
    // downloaded nothing, so their ratio is infinite rather than zero.
    it('treats a user with no traffic at all as infinite, not zero', function () {
        $stats = new TrackerStats(uploaded: 0, downloaded: 0, seedtime: 0);

        expect($stats->ratio)->toBeNull()
            ->and($stats->hasInfiniteRatio())->toBeTrue();
    });

    it('does not report a finite ratio as infinite', function () {
        $stats = new TrackerStats(uploaded: 0, downloaded: 1, seedtime: 0);

        expect($stats->ratio)->toBe(0.0)
            ->and($stats->hasInfiniteRatio())->toBeFalse();
    });

    // Every source column is unsigned. A negative figure is a bug in the
    // implementation, and failing here is cheaper than rendering it.
    it('refuses a negative figure', function (string $field) {
        $args = ['uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, $field => -1];

        new TrackerStats(...$args);
    })->with(['uploaded', 'downloaded', 'seedtime'])
        ->throws(InvalidArgumentException::class);
});
