<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member has been away from a torrent they still owe seeding on: a strike
 * follows if they stay away. The moment to tell them which torrent.
 */
class HitAndRunPrewarned
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly int $torrentId,
        public readonly int $seededSeconds,
        public readonly int $requiredSeconds,
    ) {}
}
