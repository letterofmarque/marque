<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;

/**
 * Recomputes every derived total from the ledger.
 *
 * The answer to "is a wrong number wrong forever?" — and it must exist before
 * an incident needs it, not be written in a hurry during one. That is the
 * whole reason it ships with this Spec rather than being left as a follow-up.
 *
 * Everything the site reads about a user's transfer is derived from ledger
 * rows, so a corrupted projection is not a loss, it is a stale cache. This
 * throws the cache away and adds the deltas up again.
 *
 * See Spec #99.
 */
class LedgerRebuilder
{
    /**
     * Recompute totals, optionally for a single user.
     *
     * Scoped rebuilds exist for the common case: one disputed ratio, not a
     * whole-tracker outage. Rebuilding everyone to settle one argument would
     * be a much bigger hammer than the problem.
     */
    public function rebuild(?int $userId = null): void
    {
        DB::transaction(function () use ($userId) {
            $this->resetProjections($userId);
            $this->replayLedger($userId);

            // Only a full rebuild may move the watermark. A scoped rebuild has
            // deliberately ignored other users' rows, so claiming to have
            // consumed the ledger up to here would strand their deltas
            // permanently — the aggregator would never look at them again.
            if ($userId === null) {
                $this->syncCursor();
            }
        });
    }

    protected function resetProjections(?int $userId): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $table = (new $userModel)->getTable();

        DB::table($table)
            ->when($userId !== null, fn ($q) => $q->where('id', $userId))
            ->update(['uploaded' => 0, 'downloaded' => 0]);

        DB::table('torrent_user')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->update(['uploaded' => 0, 'downloaded' => 0]);
    }

    protected function replayLedger(?int $userId): void
    {
        $watermark = LedgerCursor::positionFor(LedgerAggregator::STREAM);

        // Sum in the database rather than walking rows in PHP — a ledger with
        // months of history is far too large to hydrate, and this is the path
        // someone reaches for when something has already gone wrong.
        $perTorrent = AnnounceLog::query()
            ->where('id', '<=', $watermark)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(upload_delta) as up, SUM(download_delta) as down')
            ->get();

        foreach ($perTorrent as $row) {
            $this->writeTorrentUser(
                (int) $row->user_id,
                (int) $row->torrent_id,
                (int) $row->up,
                (int) $row->down,
            );
        }

        $perUser = AnnounceLog::query()
            ->where('id', '<=', $watermark)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(upload_delta) as up, SUM(download_delta) as down')
            ->get();

        $userModel = config('trove.user_model', 'App\\Models\\User');
        $table = (new $userModel)->getTable();

        foreach ($perUser as $row) {
            DB::table($table)
                ->where('id', $row->user_id)
                ->update([
                    'uploaded' => (int) $row->up,
                    'downloaded' => (int) $row->down,
                ]);
        }
    }

    protected function writeTorrentUser(int $userId, int $torrentId, int $uploaded, int $downloaded): void
    {
        $existing = DB::table('torrent_user')
            ->where('user_id', $userId)
            ->where('torrent_id', $torrentId)
            ->first();

        if ($existing === null) {
            DB::table('torrent_user')->insert([
                'user_id' => $userId,
                'torrent_id' => $torrentId,
                'uploaded' => $uploaded,
                'downloaded' => $downloaded,
                'seedtime' => 0,
                'times_completed' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        // Only the byte columns are rebuilt. Completion dates and counts are
        // not derivable from deltas, and a pre-ledger opening balance has no
        // completion history behind it at all — overwriting those would
        // destroy real data to fix a cache.
        DB::table('torrent_user')
            ->where('id', $existing->id)
            ->update([
                'uploaded' => $uploaded,
                'downloaded' => $downloaded,
                'updated_at' => now(),
            ]);
    }

    /**
     * The watermark is left exactly where it was.
     *
     * A rebuild replays the ledger *up to* the existing watermark, so moving
     * it forward would claim to have counted rows this rebuild deliberately
     * ignored — stranding them permanently, since the aggregator only ever
     * looks above the watermark. The next aggregation run picks up the backlog
     * normally.
     *
     * This method exists to make that a stated decision rather than an
     * omission someone later "fixes".
     */
    protected function syncCursor(): void
    {
        // Intentionally nothing.
    }
}
