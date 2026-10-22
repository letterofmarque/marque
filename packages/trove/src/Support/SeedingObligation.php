<?php

declare(strict_types=1);

namespace Marque\Trove\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Marque\Trove\Enums\ObligationState;

/**
 * A torrent a member owes seeding on, as the tracker's hit-and-run rule sees
 * it (Job #256). Returned by SeedingObligationsInterface::obligationsFor().
 *
 * Met by seeding $requiredSeconds in all, or by reaching $requiredRatio on the
 * torrent where the tracker accepts one (null: time only), whichever is first.
 * Raw figures: formatting is the frontend's.
 */
final class SeedingObligation
{
    public readonly ?Carbon $until;

    /**
     * @param  int  $seededSeconds  Seeded on this torrent so far.
     * @param  int  $requiredSeconds  What the rule asks for.
     * @param  float|null  $ratio  Uploaded / downloaded on this torrent. Null is infinite (nothing downloaded).
     * @param  float|null  $requiredRatio  The ratio on the torrent that also satisfies the rule, or null for time only.
     * @param  DateTimeInterface|null  $until  For a strike, when it lapses.
     */
    public function __construct(
        public readonly int $torrentId,
        public readonly string $torrentName,
        public readonly int $seededSeconds,
        public readonly int $requiredSeconds,
        public readonly ?float $ratio,
        public readonly ?float $requiredRatio,
        public readonly ObligationState $state = ObligationState::Open,
        ?DateTimeInterface $until = null,
    ) {
        if ($seededSeconds < 0 || $requiredSeconds < 0) {
            throw new InvalidArgumentException('A seeding obligation cannot have negative seconds.');
        }

        $this->until = $until === null ? null : Carbon::instance($until);
    }

    public function met(): bool
    {
        if ($this->seededSeconds >= $this->requiredSeconds) {
            return true;
        }

        return $this->requiredRatio !== null && ($this->ratio === null || $this->ratio >= $this->requiredRatio);
    }

    /**
     * Seeding still owed, in seconds. Zero once the time is met; the ratio may
     * meet it sooner.
     */
    public function remainingSeconds(): int
    {
        return max(0, $this->requiredSeconds - $this->seededSeconds);
    }
}
