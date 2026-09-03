<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Listeners;

use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Models\TorrentUser;

/**
 * Record a completion against the user's row for that torrent.
 *
 * Replaces RecordSnatch, which wrote the `snatches` table via updateOrCreate
 * and so overwrote the completion date every time — a redownload erased the
 * original. TorrentUser::recordCompletion() keeps the first date and tracks
 * repeats instead. See Spec #99.
 */
class RecordCompletion
{
    public function handle(TorrentCompleted $event): void
    {
        TorrentUser::recordCompletion($event->userId, $event->torrentId);
    }
}
