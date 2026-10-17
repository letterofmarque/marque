<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\LedgerCursor;
use RuntimeException;

/**
 * Gives 7.x history what 8.0 would have credited it, as carry.
 *
 * Rows written before 8.0 carry no seconds, and a 7.x `stopped` row credited
 * none of the bytes it reported. The ledger proves both: consecutive rows of
 * one session give the interval, and a stop's reported counters against the
 * announce before it give the bytes. This pairs them the way the announce
 * path now credits (each interval to the state it started in, capped at
 * peer_expiry, nothing across a stop, refusals never a partner), and records
 * the result as carry, adding it to the totals at the same time.
 *
 * It never rewrites a row. The ledger stays append-only; the backfilled
 * credit sits in ledger_carry beside it, where reconcile and rebuild read it.
 * Run once, and only after the aggregator has folded all of 7.x history.
 */
class LedgerBackfill
{
    /** The ledger_cursors stream that records a completed backfill. */
    public const DONE = 'time_backfilled_through';

    private const KEYSET_ROWS = 5000;

    public function __construct(
        private readonly LedgerAggregator $aggregator,
    ) {}

    /**
     * @return array<string, array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}>|null
     *                                                                                                                               what was credited, or null when there was nothing to do
     *
     * @throws RuntimeException when the aggregator has not reached the end of 7.x history yet
     */
    public function run(): ?array
    {
        $boundary = LedgerCursor::positionFor(LedgerAuditor::TIME_COLUMNS_FROM);

        if ($boundary === 0 || LedgerCursor::positionFor(self::DONE) >= $boundary) {
            return null;
        }

        if (LedgerCursor::positionFor(LedgerAggregator::STREAM) < $boundary) {
            throw new RuntimeException(
                "The aggregator has folded the ledger to id ".LedgerCursor::positionFor(LedgerAggregator::STREAM)
                .", not yet to {$boundary}, the last row written before 8.0. Let bloodhound:aggregate-ledger catch up and run this again."
            );
        }

        $credit = $this->derive($boundary);

        DB::connection((new LedgerCarry)->getConnectionName())->transaction(function () use ($credit, $boundary) {
            DB::transaction(function () use ($credit, $boundary) {
                $this->aggregator->ensureCursor();
                $this->aggregator->lockCursor();

                foreach ($credit as $amounts) {
                    $carry = LedgerCarry::query()->firstOrNew(['user_id' => $amounts['user_id'], 'torrent_id' => $amounts['torrent_id']]);

                    foreach (['uploaded', 'downloaded', 'seedtime', 'leechtime'] as $column) {
                        $carry->{$column} = (int) $carry->{$column} + $amounts[$column];
                    }

                    $carry->save();
                }

                $this->aggregator->addToTotals($credit);

                DB::table('ledger_cursors')->insertOrIgnore([
                    'stream' => self::DONE, 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('ledger_cursors')->where('stream', self::DONE)->update(['position' => $boundary, 'updated_at' => now()]);
            });
        });

        return $credit;
    }

    /**
     * Walk 7.x rows per (user, torrent, peer) in id order and sum what each
     * consecutive pair would have been credited.
     *
     * @return array<string, array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}>
     */
    private function derive(int $boundary): array
    {
        $cap = (int) config('threepio.peer_expiry', 3600);
        $credit = [];
        $previous = [];
        $at = null;

        // Keyset over (torrent_id, peer_id, id), the baseline index's order,
        // so one peer's rows arrive together and in order without loading
        // the table.
        while (true) {
            $rows = AnnounceLog::query()
                ->toBase()
                ->select(['id', 'user_id', 'torrent_id', 'peer_id', 'event', 'left', 'uploaded', 'downloaded', 'upload_delta', 'download_delta', 'prior_up', 'anti_cheat_flagged', 'created_at'])
                ->where('id', '<=', $boundary)
                ->when($at !== null, fn ($query) => $query->where(fn ($q) => $q
                    ->where('torrent_id', '>', $at[0])
                    ->orWhere(fn ($q) => $q->where('torrent_id', $at[0])->where('peer_id', '>', $at[1]))
                    ->orWhere(fn ($q) => $q->where('torrent_id', $at[0])->where('peer_id', $at[1])->where('id', '>', $at[2]))))
                ->orderBy('torrent_id')->orderBy('peer_id')->orderBy('id')
                ->limit(self::KEYSET_ROWS)
                ->get();

            if ($rows->isEmpty()) {
                return $credit;
            }

            foreach ($rows as $row) {
                if ($at === null || $at[0] !== (int) $row->torrent_id || $at[1] !== $row->peer_id) {
                    $previous = [];
                }

                $at = [(int) $row->torrent_id, $row->peer_id, (int) $row->id];

                // A refusal credited nothing and diffed against nothing; an
                // opening balance is not an announce.
                if ($row->event === AnnounceLog::EVENT_OPENING_BALANCE || ((bool) $row->anti_cheat_flagged && $row->prior_up === null)) {
                    continue;
                }

                $key = (int) $row->user_id;
                $last = $previous[$key] ?? null;
                $previous[$key] = $row;

                if ($last === null || $last->event === 'stopped') {
                    continue;
                }

                $pair = $row->user_id.':'.$row->torrent_id;
                $credit[$pair] ??= ['user_id' => (int) $row->user_id, 'torrent_id' => (int) $row->torrent_id, 'uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];

                $seconds = min(max(0, strtotime((string) $row->created_at) - strtotime((string) $last->created_at)), $cap);
                $credit[$pair][(int) $last->left === 0 ? 'seedtime' : 'leechtime'] += $seconds;

                // 7.x credited a stop nothing; its counters say what moved.
                if ($row->event === 'stopped' && $row->prior_up === null && (int) $row->upload_delta === 0 && (int) $row->download_delta === 0) {
                    $credit[$pair]['uploaded'] += self::delta((int) $row->uploaded, (int) $last->uploaded);
                    $credit[$pair]['downloaded'] += self::delta((int) $row->downloaded, (int) $last->downloaded);
                }
            }
        }
    }

    private static function delta(int $reported, int $prior): int
    {
        return $reported >= $prior ? $reported - $prior : $reported;
    }
}
