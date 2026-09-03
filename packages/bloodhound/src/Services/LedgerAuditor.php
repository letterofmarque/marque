<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;

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
     * Compare the projections against the ledger they are derived from.
     *
     * Only rows the aggregator has actually consumed are counted as expected:
     * a backlog is not drift, and conflating the two would make every busy
     * moment look like corruption.
     */
    public function reconcile(): LedgerReport
    {
        $watermark = LedgerCursor::positionFor(LedgerAggregator::STREAM);

        return new LedgerReport(
            userDrift: $this->userDrift($watermark),
            torrentUserDrift: $this->torrentUserDrift($watermark),
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
     * @return array<int, array{user_id: int, column: string, recorded: int, expected: int}>
     */
    protected function userDrift(int $watermark): array
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $table = (new $userModel)->getTable();

        $expected = AnnounceLog::query()
            ->where('id', '<=', $watermark)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(upload_delta) as up, SUM(download_delta) as down')
            ->get()
            ->keyBy('user_id');

        $drift = [];

        foreach (DB::table($table)->select(['id', 'uploaded', 'downloaded'])->get() as $user) {
            $row = $expected->get($user->id);

            $expectedUp = (int) ($row->up ?? 0);
            $expectedDown = (int) ($row->down ?? 0);

            if ((int) $user->uploaded !== $expectedUp) {
                $drift[] = [
                    'user_id' => (int) $user->id,
                    'column' => 'uploaded',
                    'recorded' => (int) $user->uploaded,
                    'expected' => $expectedUp,
                ];
            }

            if ((int) $user->downloaded !== $expectedDown) {
                $drift[] = [
                    'user_id' => (int) $user->id,
                    'column' => 'downloaded',
                    'recorded' => (int) $user->downloaded,
                    'expected' => $expectedDown,
                ];
            }
        }

        return $drift;
    }

    /**
     * @return array<int, array{user_id: int, torrent_id: int, column: string, recorded: int, expected: int}>
     */
    protected function torrentUserDrift(int $watermark): array
    {
        $expected = AnnounceLog::query()
            ->where('id', '<=', $watermark)
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(upload_delta) as up, SUM(download_delta) as down')
            ->get()
            ->keyBy(fn ($row) => $row->user_id.':'.$row->torrent_id);

        $drift = [];

        foreach (DB::table('torrent_user')->get() as $row) {
            $key = $row->user_id.':'.$row->torrent_id;
            $sums = $expected->get($key);

            $expectedUp = (int) ($sums->up ?? 0);
            $expectedDown = (int) ($sums->down ?? 0);

            if ((int) $row->uploaded !== $expectedUp) {
                $drift[] = [
                    'user_id' => (int) $row->user_id,
                    'torrent_id' => (int) $row->torrent_id,
                    'column' => 'uploaded',
                    'recorded' => (int) $row->uploaded,
                    'expected' => $expectedUp,
                ];
            }

            if ((int) $row->downloaded !== $expectedDown) {
                $drift[] = [
                    'user_id' => (int) $row->user_id,
                    'torrent_id' => (int) $row->torrent_id,
                    'column' => 'downloaded',
                    'recorded' => (int) $row->downloaded,
                    'expected' => $expectedDown,
                ];
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
