<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\LedgerCursor;

/**
 * Recomputes every derived total from the ledger.
 *
 * The answer to "is a wrong number wrong forever?" — and it must exist before
 * an incident needs it, not be written in a hurry during one. That is the
 * whole reason it ships with this Spec rather than being left as a follow-up.
 *
 * Everything the site reads about a user's transfer is summed from ledger
 * rows (bytes and seconds, each row carrying its own credit) plus what
 * pruning carried forward, so a corrupted projection is not a loss, it is a
 * stale cache. This throws the cache away and adds it up again.
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
        $aggregator = app(LedgerAggregator::class);
        $aggregator->ensureCursor();

        DB::transaction(function () use ($userId, $aggregator) {
            // The aggregator's lock, first. A fold that committed between the
            // reset and the replay below would be overwritten by totals that
            // never saw it (F13); holding the cursor makes a fold wait until
            // the rebuild has committed, then go on from the same position.
            $aggregator->lockCursor();

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
            ->selectRaw('user_id, torrent_id, SUM(upload_delta) as up, SUM(download_delta) as down, SUM(seed_seconds) as seed, SUM(leech_seconds) as leech')
            ->get();

        /** @var array<string, array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}> $totals */
        $totals = [];

        $add = function (int $user, int $torrent, array $amounts) use (&$totals): void {
            $key = $user.':'.$torrent;
            $totals[$key] ??= ['user_id' => $user, 'torrent_id' => $torrent, 'uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];

            foreach ($amounts as $column => $amount) {
                $totals[$key][$column] += $amount;
            }
        };

        foreach ($perTorrent as $row) {
            $add((int) $row->user_id, (int) $row->torrent_id, [
                'uploaded' => (int) $row->up,
                'downloaded' => (int) $row->down,
                'seedtime' => (int) $row->seed,
                'leechtime' => (int) $row->leech,
            ]);
        }

        // Credit whose rows were pruned (#11021). Without it a rebuild after
        // a prune would replay only what survived, and cut the ratio of
        // everyone whose history had aged out.
        $carried = LedgerCarry::query()
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->get();

        foreach ($carried as $carry) {
            $add($carry->user_id, $carry->torrent_id, [
                'uploaded' => $carry->uploaded,
                'downloaded' => $carry->downloaded,
                'seedtime' => $carry->seedtime,
                'leechtime' => $carry->leechtime,
            ]);
        }

        $perUser = [];

        foreach ($totals as $total) {
            $this->writeTorrentUser(
                $total['user_id'],
                $total['torrent_id'],
                $total['uploaded'],
                $total['downloaded'],
                $total['seedtime'],
                $total['leechtime'],
            );

            $perUser[$total['user_id']] ??= ['uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];

            foreach (['uploaded', 'downloaded', 'seedtime', 'leechtime'] as $column) {
                $perUser[$total['user_id']][$column] += $total[$column];
            }
        }

        $userModel = config('trove.user_model', 'App\\Models\\User');
        $table = (new $userModel)->getTable();

        foreach ($perUser as $id => $sums) {
            DB::table($table)->where('id', $id)->update($sums);
        }
    }

    protected function writeTorrentUser(int $userId, int $torrentId, int $uploaded, int $downloaded, int $seedtime, int $leechtime): void
    {
        $existing = DB::table('torrent_user')
            ->where('user_id', $userId)
            ->where('torrent_id', $torrentId)
            ->first();

        if ($existing === null) {
            // The aggregator writes no row for activity that moved nothing,
            // so neither does a rebuild: a rebuilt projection has to match
            // the one it replaces, row for row, or a rebuild reads as change.
            if ($uploaded === 0 && $downloaded === 0 && $seedtime === 0 && $leechtime === 0) {
                return;
            }

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
