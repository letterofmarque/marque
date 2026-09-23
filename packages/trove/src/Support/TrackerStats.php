<?php

declare(strict_types=1);

namespace Marque\Trove\Support;

use InvalidArgumentException;

/**
 * A user's tracker figures, as the tracker has them stored.
 *
 * Returned by TrackerStatsInterface::statsFor(). Raw integers throughout —
 * bytes and seconds — because formatting is presentation and belongs to the
 * consumer, including its locale (Spec #119).
 *
 * These are the figures that exist, not the figures that are enforced. Nothing
 * here says whether this install requires a minimum ratio; see the interface.
 */
final class TrackerStats
{
    /**
     * Uploaded divided by downloaded, unrounded.
     *
     * **Null means infinite, not unknown.** It is null whenever nothing has
     * been downloaded — including a brand-new user with no traffic at all —
     * because the ratio is undefined there and the only honest reading of
     * "gave without taking" is unbounded. Render it as such: a consumer that
     * shows null as 0.00 tells a user with a perfect ratio they are in trouble.
     * hasInfiniteRatio() says the same thing without relying on the reader
     * knowing this.
     */
    public readonly ?float $ratio;

    /**
     * @param  int  $uploaded  Bytes.
     * @param  int  $downloaded  Bytes.
     * @param  int  $seedtime  Seconds.
     */
    public function __construct(
        public readonly int $uploaded,
        public readonly int $downloaded,
        public readonly int $seedtime,
    ) {
        foreach (['uploaded' => $uploaded, 'downloaded' => $downloaded, 'seedtime' => $seedtime] as $field => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("Tracker stats cannot be negative: {$field} is {$value}.");
            }
        }

        $this->ratio = $downloaded === 0 ? null : $uploaded / $downloaded;
    }

    public function hasInfiniteRatio(): bool
    {
        return $this->ratio === null;
    }
}
