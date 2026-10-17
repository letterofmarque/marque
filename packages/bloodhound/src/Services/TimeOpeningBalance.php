<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\LedgerCursor;

/**
 * Records stored seedtime and leechtime that no ledger row explains as carry.
 *
 * bloodhound 7.x wrote seedtime as 0, so any time an upgrading install holds
 * came from somewhere else: an import from an earlier tracker, a hand edit.
 * It is real, and nothing in the ledger accounts for it, so without this
 * reconcile reports it as drift and the "rebuild once" upgrade step zeroes it
 * (F10). The 5.0 opening balance did this for bytes; this is the same for
 * time, as carry rather than another row, since carry is never folded and so
 * nothing is zeroed and added back.
 *
 * Per (user, torrent) first, from torrent_user; what the user's own totals
 * hold beyond their torrents' goes on torrent 0, the precedent the bytes
 * opening balance set. Only a gap ABOVE what the ledger explains is
 * recorded, which makes it idempotent: once carried, there is no gap left.
 */
class TimeOpeningBalance
{
    private const COLUMNS = ['seedtime' => 'seed_seconds', 'leechtime' => 'leech_seconds'];

    /**
     * @return int carry rows written or added to
     */
    public function record(): int
    {
        $aggregator = app(LedgerAggregator::class);
        $aggregator->ensureCursor();

        // Under the aggregator's lock: a fold landing between reading what
        // the ledger explains and writing the gap would be carried twice.
        return DB::transaction(function () use ($aggregator) {
            $aggregator->lockCursor();

            return $this->recordLocked();
        });
    }

    private function recordLocked(): int
    {
        $expected = $this->expected();
        $written = 0;
        $perUser = [];

        foreach (DB::table('torrent_user')->where(fn ($q) => $q->where('seedtime', '>', 0)->orWhere('leechtime', '>', 0))->get() as $row) {
            $key = $row->user_id.':'.$row->torrent_id;
            $gap = [];

            foreach (array_keys(self::COLUMNS) as $column) {
                $gap[$column] = max(0, (int) $row->{$column} - ($expected[$key][$column] ?? 0));
            }

            $written += $this->carry((int) $row->user_id, (int) $row->torrent_id, $gap);

            foreach (array_keys(self::COLUMNS) as $column) {
                $expected[$key][$column] = ($expected[$key][$column] ?? 0) + $gap[$column];
            }
        }

        foreach ($expected as $key => $amounts) {
            $user = (int) explode(':', $key)[0];

            foreach (array_keys(self::COLUMNS) as $column) {
                $perUser[$user][$column] = ($perUser[$user][$column] ?? 0) + ($amounts[$column] ?? 0);
            }
        }

        $userModel = config('trove.user_model', 'App\\Models\\User');

        foreach (DB::table((new $userModel)->getTable())->where(fn ($q) => $q->where('seedtime', '>', 0)->orWhere('leechtime', '>', 0))->get(['id', 'seedtime', 'leechtime']) as $user) {
            $gap = [];

            foreach (array_keys(self::COLUMNS) as $column) {
                $gap[$column] = max(0, (int) $user->{$column} - ($perUser[(int) $user->id][$column] ?? 0));
            }

            $written += $this->carry((int) $user->id, 0, $gap);
        }

        return $written;
    }

    /**
     * Time the ledger accounts for per (user, torrent): rows up to the
     * watermark plus carry, as reconcile counts it.
     *
     * @return array<string, array{seedtime: int, leechtime: int}>
     */
    private function expected(): array
    {
        $expected = [];

        $sums = AnnounceLog::query()
            ->where('id', '<=', LedgerCursor::positionFor(LedgerAggregator::STREAM))
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(seed_seconds) as seed, SUM(leech_seconds) as leech')
            ->get();

        foreach ($sums as $row) {
            $expected[$row->user_id.':'.$row->torrent_id] = ['seedtime' => (int) $row->seed, 'leechtime' => (int) $row->leech];
        }

        foreach (LedgerCarry::query()->get() as $carry) {
            $key = $carry->user_id.':'.$carry->torrent_id;
            $expected[$key]['seedtime'] = ($expected[$key]['seedtime'] ?? 0) + $carry->seedtime;
            $expected[$key]['leechtime'] = ($expected[$key]['leechtime'] ?? 0) + $carry->leechtime;
        }

        return $expected;
    }

    /**
     * @param  array{seedtime: int, leechtime: int}  $gap
     */
    private function carry(int $userId, int $torrentId, array $gap): int
    {
        if ($gap['seedtime'] === 0 && $gap['leechtime'] === 0) {
            return 0;
        }

        $carry = LedgerCarry::query()->firstOrNew(['user_id' => $userId, 'torrent_id' => $torrentId]);
        $carry->seedtime = (int) $carry->seedtime + $gap['seedtime'];
        $carry->leechtime = (int) $carry->leechtime + $gap['leechtime'];
        $carry->uploaded = (int) $carry->uploaded;
        $carry->downloaded = (int) $carry->downloaded;
        $carry->save();

        return 1;
    }
}
