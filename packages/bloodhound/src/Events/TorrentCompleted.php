<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a peer completes downloading a torrent (snatch).
 */
class TorrentCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $torrentId,
        public readonly string $ip,
        public readonly string $userAgent,
    ) {}
}
