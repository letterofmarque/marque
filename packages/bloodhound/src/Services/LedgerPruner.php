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
 * delete. Bytes carry as the deleted rows' own deltas. Time cannot: an
 * interval is credited on the row that CLOSES it, so a survivor whose opening
 * partner is pruned would lose its credit, and the deleted rows' own credits
 * would miss it. So time is derived for the affected users before the delete
 * and again after, and the difference is carried. That is exact by
 * construction, whatever shape the deletion takes.
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
     * @param  list<int>  $users
     */
    protected function pruneSlice(array $users, Carbon $cutoff, int $watermark): int
    {
        $prunable = fn () => AnnounceLog::query()
            ->whereIn('user_id', $users)
            ->where('created_at', '<', $cutoff)
            ->where('id', '<=', $watermark);

        $carry = [];

        $add = function (int $user, int $torrent, array $amounts) use (&$carry): void {
            $key = $user.':'.$torrent;
            $carry[$key] ??= ['user_id' => $user, 'torrent_id' => $torrent, 'uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];

            foreach ($amounts as $column => $amount) {
                $carry[$key][$column] += $amount;
            }
        };

        $bytes = $prunable()
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(upload_delta) as up, SUM(download_delta) as down')
            ->get();

        foreach ($bytes as $row) {
            $add((int) $row->user_id, (int) $row->torrent_id, ['uploaded' => (int) $row->up, 'downloaded' => (int) $row->down]);
        }

        $before = $this->sessionTimes($users, $watermark);

        $deleted = $prunable()->delete();

        $after = $this->sessionTimes($users, $watermark);

        foreach (array_keys($before + $after) as $key) {
            [$user, $torrent] = array_map('intval', explode(':', $key));

            $add($user, $torrent, [
                'seedtime' => ($before[$key]['seedtime'] ?? 0) - ($after[$key]['seedtime'] ?? 0),
                'leechtime' => ($before[$key]['leechtime'] ?? 0) - ($after[$key]['leechtime'] ?? 0),
            ]);
        }

        foreach ($carry as $amounts) {
            $this->addToCarry($amounts);
        }

        return $deleted;
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
