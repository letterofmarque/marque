<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * A member stayed away from a torrent they owe seeding on: a hit-and-run
 * strike, lapsing at $expiresAt unless they seed it to the requirement first.
 * $activeStrikes counts it.
 */
class HitAndRunStrike
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly int $torrentId,
        public readonly Carbon $expiresAt,
        public readonly int $activeStrikes,
    ) {}
}
