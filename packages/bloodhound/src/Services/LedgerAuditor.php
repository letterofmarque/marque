<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Support\SessionTime;

/**
 * Answers the question the whole Spec exists for: is any of this wrong?
 *
 * Durability was never the hard part. A byte count could be lost or corrupted
 * and nothing anywhere would know — the totals were accumulators with nothing
 * behind them, so a wrong number stayed wrong forever, silently, and the first
 * anyone heard of it was a user disputing a ban.
 *
 * Two independent checks:
 *
 * - reconcile(): do the projections match what the ledger says they should be?
 * - audit(): is the ledger internally coherent — does each row's arithmetic
 *   hold, and does each peer's chain of baselines run unbroken?
 *
 * The second is only possible because rows carry the baseline they were diffed
 * against. Without it a lost baseline left no trace at all.
 *
 * See Spec #99.
 */
class LedgerAuditor
{
    /**
     * The totals reconciliation checks, on users and on torrent_user.
     */
    private const TOTALS = ['uploaded', 'downloaded', 'seedtime', 'leechtime'];

    /**
     * Compare the projections against the ledger they are derived from.
     *
     * Only rows the aggregator has actually consumed are counted as expected:
     * a backlog is not drift, and conflating the two would make every busy
     * moment look like corruption.
     */
    public function reconcile(): LedgerReport
    {
        $watermark = LedgerCursor::positionFor(LedgerAggregator::STREAM);
        $expected = $this->expectedTotals($watermark);

        return new LedgerReport(
            userDrift: $this->userDrift($expected),
            torrentUserDrift: $this->torrentUserDrift($expected),
            pending: AnnounceLog::query()->where('id', '>', $watermark)->count(),
        );
    }

    /**
     * Check the ledger's own rows for internal contradictions.
     */
    public function audit(): LedgerAudit
    {
        return new LedgerAudit(
            arithmeticBreaks: $this->arithmeticBreaks(),
            chainBreaks: $this->chainBreaks(),
        );
    }

    /**
     * What every (user, torrent) total should be: the ledger's bytes up to the
     * watermark, the seedtime and leechtime its rows derive (the same
     * SessionTime derivation the aggregator credits and rebuild replays), and
     * whatever pruning carried forward (#11021).
     *
     * @return array<string, array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}>
     */
    protected function expectedTotals(int $watermark): array
    {
        $expected = [];

        $add = function (int $user, int $torrent, array $amounts) use (&$expected): void {
            $key = $user.':'.$torrent;
            $expected[$key] ??= ['user_id' => $user, 'torrent_id' => $torrent, 'uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];

            foreach ($amounts as $column => $amount) {
                $expected[$key][$column] += $amount;
            }
        };

        $bytes = AnnounceLog::query()
            ->where('id', '<=', $watermark)
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(upload_delta) as up, SUM(download_delta) as down')
            ->get();

        foreach ($bytes as $row) {
            $add((int) $row->user_id, (int) $row->torrent_id, ['uploaded' => (int) $row->up, 'downloaded' => (int) $row->down]);
        }

        $times = SessionTime::forLedger()
            ->intervals(0, $watermark)
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(seed_seconds) as seed, SUM(leech_seconds) as leech')
            ->get();

        foreach ($times as $row) {
            $add((int) $row->user_id, (int) $row->torrent_id, ['seedtime' => (int) $row->seed, 'leechtime' => (int) $row->leech]);
        }

        foreach (LedgerCarry::query()->get() as $carry) {
            $add($carry->user_id, $carry->torrent_id, [
                'uploaded' => $carry->uploaded,
                'downloaded' => $carry->downloaded,
                'seedtime' => $carry->seedtime,
                'leechtime' => $carry->leechtime,
            ]);
        }

        return $expected;
    }

    /**
     * @param  array<string, array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}>  $expected
     * @return array<int, array{user_id: int, column: string, recorded: int, expected: int}>
     */
    protected function userDrift(array $expected): array
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $table = (new $userModel)->getTable();

        $perUser = [];

        foreach ($expected as $totals) {
            foreach (self::TOTALS as $column) {
                $perUser[$totals['user_id']][$column] = ($perUser[$totals['user_id']][$column] ?? 0) + $totals[$column];
            }
        }

        $drift = [];

        foreach (DB::table($table)->select(array_merge(['id'], self::TOTALS))->get() as $user) {
            foreach (self::TOTALS as $column) {
                $recorded = (int) $user->{$column};
                $should = $perUser[(int) $user->id][$column] ?? 0;

                if ($recorded !== $should) {
                    $drift[] = [
                        'user_id' => (int) $user->id,
                        'column' => $column,
                        'recorded' => $recorded,
                        'expected' => $should,
                    ];
                }
            }
        }

        return $drift;
    }

    /**
     * @param  array<string, array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}>  $expected
     * @return array<int, array{user_id: int, torrent_id: int, column: string, recorded: int, expected: int}>
     */
    protected function torrentUserDrift(array $expected): array
    {
        $drift = [];

        foreach (DB::table('torrent_user')->get() as $row) {
            $totals = $expected[$row->user_id.':'.$row->torrent_id] ?? null;

            foreach (self::TOTALS as $column) {
                $recorded = (int) $row->{$column};
                $should = $totals[$column] ?? 0;

                if ($recorded !== $should) {
                    $drift[] = [
                        'user_id' => (int) $row->user_id,
                        'torrent_id' => (int) $row->torrent_id,
                        'column' => $column,
                        'recorded' => $recorded,
                        'expected' => $should,
                    ];
                }
            }
        }

        return $drift;
    }

    /**
     * Rows whose stored delta contradicts their own reported and prior values.
     *
     * A null prior is a first announce — no baseline existed, so there is no
     * arithmetic to check and nothing to flag.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function arithmeticBreaks(): array
    {
        $breaks = [];

        AnnounceLog::query()
            ->whereNotNull('prior_up')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$breaks) {
                foreach ($rows as $row) {
                    $expectedUp = max(0, $row->uploaded - $row->prior_up);
                    $expectedDown = max(0, $row->downloaded - (int) $row->prior_down);

                    if ($row->upload_delta === $expectedUp && $row->download_delta === $expectedDown) {
                        continue;
                    }

                    $breaks[] = [
                        'id' => $row->id,
                        'user_id' => $row->user_id,
                        'torrent_id' => $row->torrent_id,
                        'upload_delta' => $row->upload_delta,
                        'expected_upload_delta' => $expectedUp,
                        'download_delta' => $row->download_delta,
                        'expected_download_delta' => $expectedDown,
                    ];
                }
            });

        return $breaks;
    }

    /**
     * Rows whose baseline does not continue the previous row's for that peer.
     *
     * This is what turns a past Redis outage from invisible into a reported
     * fact. Each announce should have diffed against what the peer last told
     * us; when it did not, a baseline went missing in between and whatever
     * that peer transferred across the gap was never credited.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function chainBreaks(): array
    {
        $breaks = [];
        $previous = [];

        AnnounceLog::query()
            ->orderBy('torrent_id')
            ->orderBy('peer_id')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$breaks, &$previous) {
                foreach ($rows as $row) {
                    $key = $row->torrent_id.':'.$row->peer_id;
                    $last = $previous[$key] ?? null;

                    // First row for this peer — nothing to continue from, and
                    // a null baseline there is correct rather than suspicious.
                    if ($last !== null && $row->prior_up !== $last['uploaded']) {
                        $breaks[] = [
                            'id' => $row->id,
                            'user_id' => $row->user_id,
                            'torrent_id' => $row->torrent_id,
                            'peer_id' => $row->peer_id,
                            'prior_up' => $row->prior_up,
                            'previous_uploaded' => $last['uploaded'],
                        ];
                    }

                    $previous[$key] = [
                        'uploaded' => $row->uploaded,
                        'downloaded' => $row->downloaded,
                    ];
                }
            });

        return $breaks;
    }
}
