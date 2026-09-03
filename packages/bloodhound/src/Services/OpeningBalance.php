<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;

/**
 * Carries an upgrading install's pre-ledger totals into the ledger.
 *
 * Before the ledger, `users.uploaded` was an accumulator with nothing behind
 * it. On upgrade those totals are real — people were held to them — but
 * unexplained: no rows sum to them. Reconciliation would therefore report
 * every user's entire history as drift, on every run, forever. Alerting that
 * is wrong from the first day is alerting nobody reads, which would make the
 * mechanism this Spec exists to build worthless on arrival.
 *
 * One synthetic row per user fixes that by giving reconciliation a known
 * starting point.
 *
 * What this row asserts: the old total was correct as of migration.
 * What it does NOT assert: that the old total was ever *right*. Nothing can —
 * the per-announce history was never kept, which is the whole reason the
 * ledger now exists. An opening balance is a starting point, not evidence.
 *
 * See Spec #99's opening-balance decision.
 */
class OpeningBalance
{
    /**
     * Write an opening balance for every user carrying a pre-ledger total.
     *
     * Idempotent: a user who already has one is skipped, so running it twice
     * cannot double anyone's ratio.
     */
    public function record(int $chunkSize = 500): int
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $table = (new $userModel)->getTable();

        $alreadyRecorded = AnnounceLog::query()
            ->where('event', AnnounceLog::EVENT_OPENING_BALANCE)
            ->pluck('user_id')
            ->flip();

        $written = 0;

        DB::table($table)
            ->select(['id', 'uploaded', 'downloaded'])
            ->where(function ($query) {
                $query->where('uploaded', '>', 0)->orWhere('downloaded', '>', 0);
            })
            // chunkById, not chunk: this zeroes the very columns the query
            // filters on, so offset-based paging would shift rows out from
            // under itself and skip most of them. Keying on the id is stable
            // under the updates being made.
            ->chunkById($chunkSize, function ($users) use ($table, $alreadyRecorded, &$written) {
                $rows = [];
                $zeroed = [];

                foreach ($users as $user) {
                    if ($alreadyRecorded->has($user->id)) {
                        continue;
                    }

                    $rows[] = $this->rowFor($user);
                    $zeroed[] = $user->id;
                    $written++;
                }

                if ($rows === []) {
                    return;
                }

                DB::transaction(function () use ($table, $rows, $zeroed) {
                    AnnounceLog::insert($rows);

                    // The total is now represented by the ledger row, so the
                    // column has to go to zero for the aggregator to put it
                    // back. Leaving it in place would have the balance added
                    // on top of the figure it was copied from, doubling every
                    // migrated user's ratio.
                    //
                    // In the same transaction as the insert: a crash between
                    // the two would either lose the balance or zero a real
                    // total, and the second is unrecoverable.
                    DB::table($table)
                        ->whereIn('id', $zeroed)
                        ->update(['uploaded' => 0, 'downloaded' => 0]);
                });
            });

        return $written;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rowFor(object $user): array
    {
        return [
            'user_id' => $user->id,

            // No torrent produced this — it is the sum of a history nobody
            // kept, not activity on anything in particular.
            'torrent_id' => 0,

            'peer_id' => '',
            'event' => AnnounceLog::EVENT_OPENING_BALANCE,
            'ip' => '0.0.0.0',
            'port' => 0,
            'user_agent' => null,

            // The reported figure and the delta are the same thing here: the
            // whole total is what this row contributes.
            'uploaded' => (int) $user->uploaded,
            'downloaded' => (int) $user->downloaded,
            'left' => 0,
            'upload_delta' => (int) $user->uploaded,
            'download_delta' => (int) $user->downloaded,

            // Null, not zero. Nothing was diffed to produce this, so there is
            // no baseline — and a zero would claim a measurement nobody made.
            'prior_up' => null,
            'prior_down' => null,

            'anti_cheat_flagged' => false,
            'anti_cheat_reason' => null,
            'created_at' => now(),
        ];
    }
}
