<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\LedgerCursor;

/**
 * Records credit an earlier prune deleted without carrying (#11021).
 *
 * Before pruning carried forward, a prune deleted aggregated ledger rows and
 * kept nothing. The credit stayed on the users, but no ledger row explains it
 * any more, so reconcile reports it as drift every day, and the rebuild it
 * recommends would erase it.
 *
 * This records each such gap as carry: the stance Spec #99 took for an
 * upgrading install's pre-ledger totals. What it asserts: the stored total was
 * correct as of now. What it does NOT assert: that it was ever right. Nothing
 * can, because the rows that would show it were deleted.
 *
 * It records only what pruning could have caused:
 *
 * - per (user, torrent). The aggregator writes a torrent_user row for every
 *   pair that moved bytes, so pruned credit always leaves a gap there. A gap
 *   only on the user's own total means the two projections disagree with each
 *   other, which is real drift, and stays reported.
 * - only where the stored total EXCEEDS the ledger. Pruning removes credit;
 *   it never adds it. A total below its ledger is real drift.
 * - only bytes. Seedtime was never credited before Job #112, so no stored
 *   seedtime can be pruned history.
 *
 * Idempotent: once a gap is carried, the ledger explains it and there is no
 * gap left to record.
 */
class PrunedHistory
{
    /**
     * @return array{rows: int, users: int, uploaded: int, downloaded: int}
     */
    public function record(): array
    {
        $watermark = LedgerCursor::positionFor(LedgerAggregator::STREAM);

        $ledger = AnnounceLog::query()
            ->where('id', '<=', $watermark)
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(upload_delta) as up, SUM(download_delta) as down')
            ->get()
            ->keyBy(fn ($row) => $row->user_id.':'.$row->torrent_id);

        $carried = LedgerCarry::query()
            ->get()
            ->keyBy(fn (LedgerCarry $carry) => $carry->user_id.':'.$carry->torrent_id);

        $recorded = ['rows' => 0, 'users' => 0, 'uploaded' => 0, 'downloaded' => 0];
        $users = [];

        $connection = DB::connection((new AnnounceLog)->getConnectionName());

        $connection->transaction(function () use ($ledger, $carried, &$recorded, &$users) {
            DB::table('torrent_user')
                ->select(['id', 'user_id', 'torrent_id', 'uploaded', 'downloaded'])
                ->orderBy('id')
                ->chunkById(1000, function ($rows) use ($ledger, $carried, &$recorded, &$users) {
                    foreach ($rows as $row) {
                        $key = $row->user_id.':'.$row->torrent_id;
                        $sums = $ledger->get($key);
                        $carry = $carried->get($key);

                        $up = (int) $row->uploaded - (int) ($sums->up ?? 0) - ($carry->uploaded ?? 0);
                        $down = (int) $row->downloaded - (int) ($sums->down ?? 0) - ($carry->downloaded ?? 0);

                        // Each column on its own: a gap above the ledger is
                        // recorded, one below it is not.
                        $up = max(0, $up);
                        $down = max(0, $down);

                        if ($up === 0 && $down === 0) {
                            continue;
                        }

                        $record = $carry ?? new LedgerCarry([
                            'user_id' => (int) $row->user_id,
                            'torrent_id' => (int) $row->torrent_id,
                        ]);

                        $record->uploaded = (int) $record->uploaded + $up;
                        $record->downloaded = (int) $record->downloaded + $down;
                        $record->save();

                        $recorded['rows']++;
                        $recorded['uploaded'] += $up;
                        $recorded['downloaded'] += $down;
                        $users[(int) $row->user_id] = true;
                    }
                });
        });

        $recorded['users'] = count($users);

        return $recorded;
    }
}
