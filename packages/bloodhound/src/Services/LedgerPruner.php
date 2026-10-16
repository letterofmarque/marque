<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Support\SessionTime;

/**
 * Deletes aged ledger rows without losing what they credited (#11021).
 *
 * A prune used to delete and keep nothing. The totals those rows produced
 * stayed on the users, but the ledger no longer explained them: reconcile
 * reported every pruned user as drift and recommended a rebuild, and the
 * rebuild replayed only what survived, cutting real users' ratios. The tool
 * meant to settle a disputed ratio was the thing that broke it.
 *
 * Now the credit moves into ledger_carry in the same transaction as the
 * delete. What the affected users' rows produce (bytes, and the seedtime and
 * leechtime derived from them) is worked out before the delete and again
 * after, and the difference is carried. For time it has to be the difference:
 * an interval is credited on the row that CLOSES it, so the deleted rows' own
 * credits would miss a survivor whose opening partner was pruned. Doing bytes
 * the same way means no query ever needs "these rows except the kept ones".
 *
 * See Job #187.
 */
class LedgerPruner
{
    /**
     * Users per transaction. Each slice derives, deletes and carries on its
     * own, so a large prune is many bounded transactions rather than one that
     * holds the ledger for the whole run.
     */
    public const USERS_PER_SLICE = 200;

    /**
     * Ids per delete statement, and per keep-set lookup.
     */
    private const IDS_PER_DELETE = 1000;

    /**
     * The four totals a prune carries forward.
     */
    private const TOTALS = ['uploaded', 'downloaded', 'seedtime', 'leechtime'];

    /**
     * Prune rows created before $cutoff that the aggregator has consumed.
     *
     * @return int rows deleted
     */
    public function prune(Carbon $cutoff, int $watermark, int $usersPerSlice = self::USERS_PER_SLICE): int
    {
        $users = AnnounceLog::query()
            ->where('created_at', '<', $cutoff)
            ->where('id', '<=', $watermark)
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id');

        $connection = DB::connection((new AnnounceLog)->getConnectionName());
        $deleted = 0;

        foreach ($users->chunk($usersPerSlice) as $slice) {
            $deleted += $connection->transaction(
                fn () => $this->pruneSlice($slice->values()->all(), $cutoff, $watermark),
            );
        }

        return $deleted;
    }

    /**
     * Derive, delete, derive, carry the difference, for one slice of users.
     *
     * Runs inside the caller's transaction. If any part fails, the delete
     * rolls back with the carry: neither lands alone.
     *
     * Bytes and time are both carried as before − after, so no query needs
     * "these rows except the kept ones". And the delete is by primary key, in
     * id-paged chunks, of ids read and filtered in PHP. Nothing here depends
     * on a planner judging a subquery well: an earlier form put the kept set
     * in a NOT IN subquery, and PostgreSQL, with statistics that read the
     * full table as empty, re-ran it per row (past a 120s timeout where this
     * takes seconds).
     *
     * @param  list<int>  $users
     */
    protected function pruneSlice(array $users, Carbon $cutoff, int $watermark): int
    {
        $before = $this->derivedTotals($users, $watermark);
        $keep = $this->latestOpenings($users, $watermark);

        $deleted = 0;

        AnnounceLog::query()
            ->toBase()
            ->select('id')
            ->whereIn('user_id', $users)
            ->where('created_at', '<', $cutoff)
            ->where('id', '<=', $watermark)
            ->chunkById(self::IDS_PER_DELETE, function ($rows) use ($keep, &$deleted) {
                $ids = [];

                foreach ($rows as $row) {
                    if (! isset($keep[(int) $row->id])) {
                        $ids[] = (int) $row->id;
                    }
                }

                if ($ids !== []) {
                    $deleted += AnnounceLog::query()->whereIn('id', $ids)->delete();
                }
            });

        $after = $this->derivedTotals($users, $watermark);

        foreach (array_keys($before + $after) as $key) {
            [$user, $torrent] = array_map('intval', explode(':', $key));
            $amounts = ['user_id' => $user, 'torrent_id' => $torrent];

            foreach (self::TOTALS as $column) {
                $amounts[$column] = ($before[$key][$column] ?? 0) - ($after[$key][$column] ?? 0);
            }

            $this->addToCarry($amounts);
        }

        return $deleted;
    }

    /**
     * Bytes and seconds per (user, torrent) these users' rows up to the
     * watermark produce: the same sums rebuild replays.
     *
     * @param  list<int>  $users
     * @return array<string, array{uploaded: int, downloaded: int, seedtime: int, leechtime: int}>
     */
    protected function derivedTotals(array $users, int $watermark): array
    {
        $totals = [];

        $bytes = AnnounceLog::query()
            ->whereIn('user_id', $users)
            ->where('id', '<=', $watermark)
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(upload_delta) as up, SUM(download_delta) as down')
            ->get();

        foreach ($bytes as $row) {
            $totals[$row->user_id.':'.$row->torrent_id] = [
                'uploaded' => (int) $row->up,
                'downloaded' => (int) $row->down,
                'seedtime' => 0,
                'leechtime' => 0,
            ];
        }

        foreach ($this->sessionTimes($users, $watermark) as $key => $time) {
            $totals[$key] ??= ['uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];
            $totals[$key]['seedtime'] = $time['seedtime'];
            $totals[$key]['leechtime'] = $time['leechtime'];
        }

        return $totals;
    }

    /**
     * Each peer's latest aggregated announce, which is never pruned.
     *
     * Time is credited on the announce that CLOSES an interval. Delete a
     * peer's last aggregated announce and its next one, still waiting to be
     * aggregated or not yet sent, has nothing to pair with: that interval is
     * never credited, and since reconcile and rebuild then agree on the loss,
     * nothing would ever report it. Found by the independent check of Job
     * #187.
     *
     * Not kept when it is a `stopped` (nothing is credited from a stop) or an
     * opening balance (never paired at all): keeping those would protect
     * nothing. So a peer whose session ended cleanly still prunes to nothing,
     * and what stays is one row per session that ended without a stop.
     *
     * Two plain queries, returning literal ids: the latest id per peer, then
     * the event of each.
     *
     * @param  list<int>  $users
     * @return array<int, true>
     */
    protected function latestOpenings(array $users, int $watermark): array
    {
        $latest = AnnounceLog::query()
            ->toBase()
            ->selectRaw('MAX(id) as id')
            ->whereIn('user_id', $users)
            ->where('id', '<=', $watermark)
            ->groupBy('user_id', 'torrent_id', 'peer_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $keep = [];

        foreach (array_chunk($latest, self::IDS_PER_DELETE) as $chunk) {
            $rows = AnnounceLog::query()
                ->toBase()
                ->whereIn('id', $chunk)
                ->whereNotIn('event', ['stopped', AnnounceLog::EVENT_OPENING_BALANCE])
                ->pluck('id');

            foreach ($rows as $id) {
                $keep[(int) $id] = true;
            }
        }

        return $keep;
    }

    /**
     * Seconds per (user, torrent) these users' rows up to the watermark
     * derive: the same derivation the aggregator credits and rebuild replays.
     *
     * Scoped by user, a column the pairing partitions by, so every peer's
     * whole history is inside the window.
     *
     * @param  list<int>  $users
     * @return array<string, array{seedtime: int, leechtime: int}>
     */
    protected function sessionTimes(array $users, int $watermark): array
    {
        $sums = SessionTime::forLedger()
            ->intervals(0, $watermark, fn ($query) => $query->whereIn('user_id', $users))
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

    /**
     * @param  array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}  $amounts
     */
    protected function addToCarry(array $amounts): void
    {
        if ($amounts['uploaded'] === 0 && $amounts['downloaded'] === 0 && $amounts['seedtime'] === 0 && $amounts['leechtime'] === 0) {
            return;
        }

        $carry = LedgerCarry::query()->firstOrNew([
            'user_id' => $amounts['user_id'],
            'torrent_id' => $amounts['torrent_id'],
        ]);

        foreach (['uploaded', 'downloaded', 'seedtime', 'leechtime'] as $column) {
            $carry->{$column} = (int) $carry->{$column} + $amounts[$column];
        }

        $carry->save();
    }
}
