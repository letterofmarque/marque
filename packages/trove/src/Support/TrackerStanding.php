<?php

declare(strict_types=1);

namespace Marque\Trove\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Marque\Trove\Enums\StandingState;

/**
 * A member's standing against the tracker's ratio rule, as the tracker
 * decided it (#10732). Carried on TrackerStats beside the figures it was
 * judged on, so a frontend can say "on watch until Friday" or "downloading is
 * paused" without knowing which tracker decided, or how.
 *
 * The reason is written for the member: it says what's wrong and what to do.
 */
final class TrackerStanding
{
    private function __construct(
        public readonly StandingState $state,
        public readonly ?Carbon $until = null,
        public readonly ?string $reason = null,
    ) {}

    public static function ok(): self
    {
        return new self(StandingState::Ok);
    }

    /**
     * Below the rule, with until when to recover before downloading is paused.
     */
    public static function watch(DateTimeInterface $deadline, ?string $reason = null): self
    {
        return new self(StandingState::Watch, Carbon::instance($deadline), $reason);
    }

    public static function restricted(string $reason): self
    {
        return new self(StandingState::Restricted, reason: $reason);
    }

    public static function exempt(): self
    {
        return new self(StandingState::Exempt);
    }

    /**
     * Whether new downloading is paused for this member.
     */
    public function isRestricted(): bool
    {
        return $this->state === StandingState::Restricted;
    }
}
