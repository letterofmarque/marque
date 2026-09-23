<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Marque\Trove\Support\TorrentStats;

describe('TorrentStats', function () {
    it('carries the per-torrent figures and completion history', function () {
        $first = CarbonImmutable::parse('2026-01-01 12:00:00');
        $last = CarbonImmutable::parse('2026-06-01 12:00:00');

        $stats = new TorrentStats(
            uploaded: 700,
            downloaded: 1_400,
            seedtime: 3_600,
            firstCompletedAt: $first,
            lastCompletedAt: $last,
            timesCompleted: 2,
        );

        expect($stats->uploaded)->toBe(700)
            ->and($stats->downloaded)->toBe(1_400)
            ->and($stats->seedtime)->toBe(3_600)
            ->and($stats->firstCompletedAt)->toBe($first)
            ->and($stats->lastCompletedAt)->toBe($last)
            ->and($stats->timesCompleted)->toBe(2)
            ->and($stats->ratio)->toBe(0.5);
    });

    it('represents a torrent the user has never completed', function () {
        $stats = new TorrentStats(
            uploaded: 0,
            downloaded: 200,
            seedtime: 0,
            firstCompletedAt: null,
            lastCompletedAt: null,
            timesCompleted: 0,
        );

        expect($stats->firstCompletedAt)->toBeNull()
            ->and($stats->lastCompletedAt)->toBeNull()
            ->and($stats->timesCompleted)->toBe(0);
    });

    // Same semantics as TrackerStats, deliberately: a consumer should not have
    // to learn the null-means-infinite rule twice.
    it('reports an infinite ratio as null when nothing has been downloaded', function () {
        $stats = new TorrentStats(
            uploaded: 900,
            downloaded: 0,
            seedtime: 0,
            firstCompletedAt: null,
            lastCompletedAt: null,
            timesCompleted: 0,
        );

        expect($stats->ratio)->toBeNull()
            ->and($stats->hasInfiniteRatio())->toBeTrue();
    });

    it('refuses a negative figure', function (string $field) {
        $args = [
            'uploaded' => 0,
            'downloaded' => 0,
            'seedtime' => 0,
            'firstCompletedAt' => null,
            'lastCompletedAt' => null,
            'timesCompleted' => 0,
            $field => -1,
        ];

        new TorrentStats(...$args);
    })->with(['uploaded', 'downloaded', 'seedtime', 'timesCompleted'])
        ->throws(InvalidArgumentException::class);
});
