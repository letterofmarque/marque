<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member's watch ran out with their ratio still below the rule: new
 * downloading is paused until it recovers. Seeding is not.
 */
class MemberRestricted
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly float $ratio,
        public readonly float $required,
    ) {}
}
