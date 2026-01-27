<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Listeners;

use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Models\Snatch;

/**
 * Records a snatch when a torrent is completed.
 */
class RecordSnatch
{
    public function handle(TorrentCompleted $event): void
    {
        Snatch::updateOrCreate(
            [
                'user_id' => $event->userId,
                'torrent_id' => $event->torrentId,
            ],
            [
                'ip' => $event->ip,
                'user_agent' => $event->userAgent,
                'completed_at' => now(),
            ]
        );
    }
}
