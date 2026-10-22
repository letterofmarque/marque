<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member seeded a torrent they'd been struck for to the requirement: the
 * strike is cleared.
 */
class HitAndRunCleared
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly int $torrentId,
    ) {}
}
