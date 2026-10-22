<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * A member fell below the ratio rule and is on watch until $deadline: after
 * it, if still below, new downloading is paused. The moment to tell them.
 */
class RatioWatchStarted
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly float $ratio,
        public readonly float $required,
        public readonly Carbon $deadline,
    ) {}
}
