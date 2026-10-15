<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Support\SessionTime;

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

        // Time with the bytes, all four or the rebuild is half done: a user or
        // torrent with no ledger rows left would keep whatever time it had,
        // while its bytes were correctly zeroed beside it.
        $zero = ['uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];

        DB::table($table)
            ->when($userId !== null, fn ($q) => $q->where('id', $userId))
            ->update($zero);

        DB::table('torrent_user')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->update($zero);
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

        $times = $this->sessionTimes($watermark, $userId);
        $timePerUser = [];

        foreach ($perTorrent as $row) {
            $time = $times[$row->user_id.':'.$row->torrent_id] ?? ['seedtime' => 0, 'leechtime' => 0];

            $this->writeTorrentUser(
                (int) $row->user_id,
                (int) $row->torrent_id,
                (int) $row->up,
                (int) $row->down,
                $time['seedtime'],
                $time['leechtime'],
            );

            $timePerUser[$row->user_id] ??= ['seedtime' => 0, 'leechtime' => 0];
            $timePerUser[$row->user_id]['seedtime'] += $time['seedtime'];
            $timePerUser[$row->user_id]['leechtime'] += $time['leechtime'];
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
                    'seedtime' => $timePerUser[$row->user_id]['seedtime'] ?? 0,
                    'leechtime' => $timePerUser[$row->user_id]['leechtime'] ?? 0,
                ]);
        }
    }

    /**
     * Seconds seeded and leeched per (user, torrent), derived from every
     * ledger row up to the watermark (Spec #121).
     *
     * Through the same SessionTime the aggregator uses, so live and rebuilt
     * seedtime cannot be computed two ways. Set-based like the byte sums
     * above: the pairing is a window function in the database, and only the
     * per-(user, torrent) sums come back to PHP.
     *
     * This is also the backfill. Rows the aggregator folded before Spec #121
     * sit below the cursor with seedtime never derived from them; a full
     * rebuild derives it from the rows themselves, as far back as retention
     * has kept them.
     *
     * @return array<string, array{seedtime: int, leechtime: int}>
     */
    protected function sessionTimes(int $watermark, ?int $userId): array
    {
        $sums = SessionTime::forLedger()
            ->intervals(0, $watermark, fn ($q) => $q->when($userId !== null, fn ($q) => $q->where('user_id', $userId)))
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(seed_seconds) as seed, SUM(leech_seconds) as leech')
            ->get();

        $times = [];

        foreach ($sums as $sum) {
            $times[$sum->user_id.':'.$sum->torrent_id] = [
                'seedtime' => (int) $sum->seed,
                'leechtime' => (int) $sum->leech,
            ];
        }

        return $times;
    }

    protected function writeTorrentUser(int $userId, int $torrentId, int $uploaded, int $downloaded, int $seedtime, int $leechtime): void
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
                'seedtime' => $seedtime,
                'leechtime' => $leechtime,
                'times_completed' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        // Bytes and time are rebuilt; completions are not. Completion dates
        // and counts are not derivable from deltas, and a pre-ledger opening
        // balance has no completion history behind it at all — overwriting
        // those would destroy real data to fix a cache.
        DB::table('torrent_user')
            ->where('id', $existing->id)
            ->update([
                'uploaded' => $uploaded,
                'downloaded' => $downloaded,
                'seedtime' => $seedtime,
                'leechtime' => $leechtime,
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
