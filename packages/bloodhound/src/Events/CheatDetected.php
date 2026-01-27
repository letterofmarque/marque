<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when potential cheating is detected.
 */
class CheatDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $type,
        public readonly ?int $userId,
        public readonly int $torrentId,
        public readonly ?string $reason,
    ) {}
}
