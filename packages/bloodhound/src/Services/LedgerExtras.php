<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Models\LedgerAdjustment;
use Marque\Bloodhound\Models\LedgerCarry;

/**
 * What the ledger explains beyond its own rows: its carry and its adjustments.
 *
 * The ledger is its rows (announce_log), plus credit a prune or an upgrade
 * carried forward (ledger_carry, #11021), plus corrections an operator made on
 * the record (ledger_adjustments, Job #213). Every reader that asks what the
 * ledger explains reads this, so a fourth source, or a fifth reader, can't be
 * added to some readers and not others: the shape AnnounceLog::refused() gave
 * the refusal rules.
 *
 * The readers: reconcile and audit (LedgerAuditor), rebuild (LedgerRebuilder),
 * and the gap finders that carry what the ledger doesn't explain
 * (TimeOpeningBalance, PrunedHistory). A gap finder that left adjustments out
 * would carry adjusted credit a second time.
 */
class LedgerExtras
{
    /**
     * Carry plus adjustments per (user, torrent), optionally for one user.
     *
     * `first` and `last` are when the pair's extras began and last changed, as
     * Unix timestamps: the rebuild needs them to date a torrent_user row it has
     * to re-create. Carry contributes its own created_at and updated_at; an
     * adjustment, which is never changed, its created_at for both.
     *
     * @return array<string, array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int, first: int|null, last: int|null}>
     */
    public function perPair(?int $userId = null): array
    {
        $extras = [];

        $add = function (int $user, int $torrent, array $amounts, ?int $first, ?int $last) use (&$extras): void {
            $key = $user.':'.$torrent;
            $extras[$key] ??= ['user_id' => $user, 'torrent_id' => $torrent, 'uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0, 'first' => null, 'last' => null];

            foreach ($amounts as $column => $amount) {
                $extras[$key][$column] += $amount;
            }

            if ($first !== null) {
                $extras[$key]['first'] = $extras[$key]['first'] === null ? $first : min($extras[$key]['first'], $first);
            }

            if ($last !== null) {
                $extras[$key]['last'] = $extras[$key]['last'] === null ? $last : max($extras[$key]['last'], $last);
            }
        };

        $carried = LedgerCarry::query()
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->get();

        foreach ($carried as $carry) {
            $add($carry->user_id, $carry->torrent_id, [
                'uploaded' => $carry->uploaded,
                'downloaded' => $carry->downloaded,
                'seedtime' => $carry->seedtime,
                'leechtime' => $carry->leechtime,
            ], $carry->created_at?->getTimestamp(), $carry->updated_at?->getTimestamp());
        }

        // An upgrade's own migrations run the gap finders before this table
        // is created (record_pruned_history, record_time_opening_balances).
        // No adjustment can exist yet, so no table means none.
        if (! Schema::connection((new LedgerAdjustment)->getConnectionName())->hasTable('ledger_adjustments')) {
            return $extras;
        }

        // Summed in the database: one row per decision, and a busy tracker's
        // operators could make many.
        $adjusted = LedgerAdjustment::query()
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(uploaded) as up, SUM(downloaded) as down, SUM(seedtime) as seed, SUM(leechtime) as leech, MIN(created_at) as first_at, MAX(created_at) as last_at')
            ->toBase()
            ->get();

        foreach ($adjusted as $row) {
            $add((int) $row->user_id, (int) $row->torrent_id, [
                'uploaded' => (int) $row->up,
                'downloaded' => (int) $row->down,
                'seedtime' => (int) $row->seed,
                'leechtime' => (int) $row->leech,
            ], self::timestamp($row->first_at), self::timestamp($row->last_at));
        }

        return $extras;
    }

    /**
     * A MIN/MAX of created_at as each engine returns it (a string, in the
     * app's timezone, as Eloquent wrote it), as a Unix timestamp.
     */
    private static function timestamp(mixed $value): ?int
    {
        return $value === null ? null : Carbon::parse((string) $value, config('app.timezone'))->getTimestamp();
    }
}
