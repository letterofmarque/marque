<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Listeners;

use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Trove\Models\Torrent;

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
        $before = TorrentUser::query()
            ->where('user_id', $event->userId)
            ->where('torrent_id', $event->torrentId)
            ->value('times_completed') ?? 0;

        $row = TorrentUser::recordCompletion($event->userId, $event->torrentId);

        // The torrent's total moves only when this user's count did. Both
        // numbers then answer the same question — "how many completions have
        // happened" — instead of one counting downloads and the other counting
        // announces, which is how they diverged.
        if ($row->times_completed > $before) {
            Torrent::query()
                ->whereKey($event->torrentId)
                ->increment('times_completed');
        }
    }
}
