<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;

/**
 * Deletes aged ledger rows without losing what they credited (#11021).
 *
 * A prune used to delete and keep nothing. The totals those rows produced
 * stayed on the users, but the ledger no longer explained them: reconcile
 * reported every pruned user as drift and recommended a rebuild, and the
 * rebuild replayed only what survived, cutting real users' ratios.
 *
 * Every row carries its own credit (bytes and seconds, written at announce
 * time), so what a deleted row contributed is exactly its own four columns.
 * Each chunk sums those per (user, torrent) into ledger_carry and deletes the
 * rows, by primary key, in one transaction. Nothing pairs rows, so no row has
 * to be kept to protect another, and "prune then rebuild" reproduces the
 * pre-prune totals by construction.
 *
 * See Job #187 and Job #193.
 */
class LedgerPruner
{
    /**
     * Rows per transaction: sum, carry, delete.
     */
    public const ROWS_PER_CHUNK = 1000;

    /**
     * Prune rows created before $cutoff that the aggregator has consumed.
     *
     * The watermark floor is not optional: a row the aggregator has not
     * folded is credit not yet in the totals, and carrying it would count it
     * twice once the aggregator got there.
     *
     * @return int rows deleted
     */
    public function prune(Carbon $cutoff, int $watermark, int $rowsPerChunk = self::ROWS_PER_CHUNK): int
    {
        $connection = DB::connection((new AnnounceLog)->getConnectionName());
        $deleted = 0;
        $after = 0;

        while (true) {
            $count = $connection->transaction(function () use ($cutoff, $watermark, $rowsPerChunk, &$after) {
                $rows = AnnounceLog::query()
                    ->toBase()
                    ->select(['id', 'user_id', 'torrent_id', 'upload_delta', 'download_delta', 'seed_seconds', 'leech_seconds'])
                    ->where('id', '>', $after)
                    ->where('id', '<=', $watermark)
                    ->where('created_at', '<', $cutoff)
                    ->orderBy('id')
                    ->limit($rowsPerChunk)
                    ->get();

                if ($rows->isEmpty()) {
                    return 0;
                }

                $carried = [];

                foreach ($rows as $row) {
                    $key = $row->user_id.':'.$row->torrent_id;
                    $carried[$key] ??= ['user_id' => (int) $row->user_id, 'torrent_id' => (int) $row->torrent_id, 'uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];
                    $carried[$key]['uploaded'] += (int) $row->upload_delta;
                    $carried[$key]['downloaded'] += (int) $row->download_delta;
                    $carried[$key]['seedtime'] += (int) $row->seed_seconds;
                    $carried[$key]['leechtime'] += (int) $row->leech_seconds;
                }

                foreach ($carried as $amounts) {
                    $this->addToCarry($amounts);
                }

                $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
                $after = max($ids);

                return AnnounceLog::query()->whereIn('id', $ids)->delete();
            });

            if ($count === 0) {
                return $deleted;
            }

            $deleted += $count;
        }
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
