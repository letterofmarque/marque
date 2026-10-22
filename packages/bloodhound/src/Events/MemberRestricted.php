<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * New downloading is paused for a member: their ratio watch ran out with the
 * ratio still below the rule, or they reached the hit-and-run strike limit
 * ($strikes, 0 when it was the ratio alone). Seeding is not paused.
 */
class MemberRestricted
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly float $ratio,
        public readonly float $required,
        public readonly int $strikes = 0,
    ) {}
}
