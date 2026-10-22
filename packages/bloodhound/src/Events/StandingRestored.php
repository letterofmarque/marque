<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Marque\Trove\Enums\StandingState;

/**
 * A member's watch or restriction is over: their ratio recovered, they became
 * exempt, or enforcement was switched off. $previous says which they were.
 */
class StandingRestored
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly StandingState $previous,
    ) {}
}
