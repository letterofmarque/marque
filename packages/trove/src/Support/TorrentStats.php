<?php

declare(strict_types=1);

namespace Marque\Trove\Support;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * What one user has done on one torrent.
 *
 * Returned by TrackerStatsInterface::statsForTorrent(). The completion history
 * is the part that matters beyond bytes: the first completion date survives a
 * redownload, which is the date a hit-and-run rule needs, and timesCompleted
 * records that they came back. Neither can be derived from the byte figures,
 * nor the byte figures from them.
 */
final class TorrentStats
{
    /**
     * Uploaded divided by downloaded for this torrent, unrounded.
     *
     * Null means infinite, exactly as on TrackerStats::$ratio — the same rule
     * in both places, so a consumer learns it once.
     */
    public readonly ?float $ratio;

    /**
     * @param  int  $uploaded  Bytes.
     * @param  int  $downloaded  Bytes.
     * @param  int  $seedtime  Seconds.
     * @param  DateTimeInterface|null  $firstCompletedAt  Null if never completed.
     * @param  DateTimeInterface|null  $lastCompletedAt  Null if never completed.
     */
    public function __construct(
        public readonly int $uploaded,
        public readonly int $downloaded,
        public readonly int $seedtime,
        public readonly ?DateTimeInterface $firstCompletedAt,
        public readonly ?DateTimeInterface $lastCompletedAt,
        public readonly int $timesCompleted,
    ) {
        $counts = [
            'uploaded' => $uploaded,
            'downloaded' => $downloaded,
            'seedtime' => $seedtime,
            'timesCompleted' => $timesCompleted,
        ];

        foreach ($counts as $field => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("Torrent stats cannot be negative: {$field} is {$value}.");
            }
        }

        $this->ratio = $downloaded === 0 ? null : $uploaded / $downloaded;
    }

    public function hasInfiniteRatio(): bool
    {
        return $this->ratio === null;
    }
}
