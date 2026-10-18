<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCarry;
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
     * The totals reconciliation checks, on users and on torrent_user.
     */
    private const TOTALS = ['uploaded', 'downloaded', 'seedtime', 'leechtime'];

    /**
     * The ledger_cursors stream holding the last id written before rows carried
     * their own time (bloodhound 8.0.0). Rows at or below it are checked by the
     * rules they were written under.
     */
    public const TIME_COLUMNS_FROM = 'time_columns_from';

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
     * What every (user, torrent) total should be: the sum of the ledger's
     * rows up to the watermark (each row carries its own bytes and seconds),
     * plus whatever pruning carried forward (#11021).
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

        $sums = AnnounceLog::query()
            ->where('id', '<=', $watermark)
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(upload_delta) as up, SUM(download_delta) as down, SUM(seed_seconds) as seed, SUM(leech_seconds) as leech')
            ->get();

        foreach ($sums as $row) {
            $add((int) $row->user_id, (int) $row->torrent_id, [
                'uploaded' => (int) $row->up,
                'downloaded' => (int) $row->down,
                'seedtime' => (int) $row->seed,
                'leechtime' => (int) $row->leech,
            ]);
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
     * Rows whose credit contradicts their own reported, prior and timing values.
     *
     * For a row written by 8.0 (above the time-columns boundary):
     *
     * - bytes, per direction: the reported counter minus the prior, or the
     *   reported counter itself when it went backwards (a restarted client).
     *   A flagged row with priors is a forfeit: each direction is either that
     *   or 0.
     * - time: the seconds credited equal `created_at - prior_at`, capped at
     *   peer_expiry, all in one of seed or leech.
     * - a row without a baseline (a first announce, a refusal) credits nothing.
     *
     * A 7.x row is held to the rule it was written under: bytes only,
     * backwards counters clamped to 0.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function arithmeticBreaks(): array
    {
        $breaks = [];
        $boundary = LedgerCursor::positionFor(self::TIME_COLUMNS_FROM);
        $cap = (int) config('threepio.peer_expiry', 3600);

        AnnounceLog::query()
            ->where('event', '!=', AnnounceLog::EVENT_OPENING_BALANCE)
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$breaks, $boundary, $cap) {
                foreach ($rows as $row) {
                    $reason = $row->id <= $boundary
                        ? $this->legacyArithmetic($row)
                        : $this->arithmetic($row, $cap);

                    if ($reason === null) {
                        continue;
                    }

                    $breaks[] = [
                        'id' => $row->id,
                        'user_id' => $row->user_id,
                        'torrent_id' => $row->torrent_id,
                        'reason' => $reason,
                        'upload_delta' => $row->upload_delta,
                        'download_delta' => $row->download_delta,
                        'seed_seconds' => $row->seed_seconds,
                        'leech_seconds' => $row->leech_seconds,
                    ];
                }
            });

        return $breaks;
    }

    protected function arithmetic(AnnounceLog $row, int $cap): ?string
    {
        if ($row->prior_up === null) {
            return $row->upload_delta === 0 && $row->download_delta === 0
                && $row->seed_seconds === 0 && $row->leech_seconds === 0
                ? null
                : 'credit with no baseline';
        }

        foreach ([['upload_delta', 'uploaded', 'prior_up'], ['download_delta', 'downloaded', 'prior_down']] as [$delta, $reported, $prior]) {
            $reportedValue = (int) $row->{$reported};
            $priorValue = (int) $row->{$prior};
            $expected = $reportedValue >= $priorValue ? $reportedValue - $priorValue : $reportedValue;
            $allowed = $row->anti_cheat_flagged ? [0, $expected] : [$expected];

            if (! in_array($row->{$delta}, $allowed, true)) {
                return "{$delta} {$row->{$delta}}, expected {$expected}";
            }
        }

        $expectedSeconds = $row->prior_at !== null
            ? min(max(0, $row->created_at->getTimestamp() - $row->prior_at->getTimestamp()), $cap)
            : 0;

        if ($row->seed_seconds + $row->leech_seconds !== $expectedSeconds
            || ($row->seed_seconds > 0 && $row->leech_seconds > 0)) {
            return "seconds {$row->seed_seconds} seed + {$row->leech_seconds} leech, expected {$expectedSeconds}";
        }

        return null;
    }

    protected function legacyArithmetic(AnnounceLog $row): ?string
    {
        if ($row->prior_up === null) {
            return null;
        }

        $expectedUp = max(0, $row->uploaded - $row->prior_up);
        $expectedDown = max(0, $row->downloaded - (int) $row->prior_down);

        return $row->upload_delta === $expectedUp && $row->download_delta === $expectedDown
            ? null
            : "upload_delta {$row->upload_delta}, expected {$expectedUp}";
    }

    /**
     * Rows whose baseline does not continue the previous row's for that peer.
     *
     * This is what turns a lost baseline from invisible into a reported fact.
     * Per (user, torrent, peer), in id order, over the rows that take part in
     * the chain (refusals and opening balances do not: they credit nothing
     * and diff against nothing):
     *
     * - after a `stopped`, or a session the member ended, the next row starts
     *   a new session: no baseline;
     * - otherwise the next row's baseline is what the previous one reported,
     *   and its prior_at is the previous row's created_at;
     * - a row with no baseline after one without a stop means the baseline
     *   was lost, unless the gap is longer than baseline retention, after
     *   which none is kept by design;
     * - two rows diffed against the same baseline are one interval credited
     *   twice (a Redis write that failed after its ledger row, or two
     *   announces from one peer at once).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function chainBreaks(): array
    {
        $breaks = [];
        $previous = [];
        $boundary = LedgerCursor::positionFor(self::TIME_COLUMNS_FROM);
        $retention = (int) config('bloodhound.baseline_retention', 7 * 86_400);

        AnnounceLog::query()
            ->where('event', '!=', AnnounceLog::EVENT_OPENING_BALANCE)
            ->where(fn ($query) => $query->where('anti_cheat_flagged', false)->orWhereNotNull('prior_up'))
            ->orderBy('torrent_id')
            ->orderBy('peer_id')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$breaks, &$previous, $boundary, $retention) {
                foreach ($rows as $row) {
                    $key = $row->user_id.':'.$row->torrent_id.':'.$row->peer_id;
                    $last = $previous[$key] ?? null;

                    $reason = $last !== null && $row->id > $boundary
                        ? $this->chainReason($row, $last, $retention)
                        : null;

                    if ($reason !== null) {
                        $breaks[] = [
                            'id' => $row->id,
                            'user_id' => $row->user_id,
                            'torrent_id' => $row->torrent_id,
                            'peer_id' => $row->peer_id,
                            'reason' => $reason,
                            'prior_up' => $row->prior_up,
                            'previous_uploaded' => $last['uploaded'],
                        ];
                    }

                    $previous[$key] = [
                        'id' => $row->id,
                        'event' => $row->event,
                        'uploaded' => $row->uploaded,
                        'downloaded' => $row->downloaded,
                        'created_at' => $row->created_at->getTimestamp(),
                        'prior_at' => $row->prior_at?->getTimestamp(),
                        'prior_up' => $row->prior_up,
                        'prior_down' => $row->prior_down,
                        'legacy' => $row->id <= $boundary,
                    ];
                }
            });

        return $breaks;
    }

    /**
     * @param  array{id: int, event: string, uploaded: int, downloaded: int, created_at: int, prior_at: ?int, prior_up: ?int, prior_down: ?int, legacy: bool}  $last
     */
    protected function chainReason(AnnounceLog $row, array $last, int $retention): ?string
    {
        // A session the member ended credits nothing and diffs against
        // nothing: it closes the chain without continuing it.
        if ($row->event === AnnounceLog::EVENT_ENDED) {
            return $row->prior_up === null ? null : 'an ended session with a baseline';
        }

        if (in_array($last['event'], AnnounceLog::SESSION_ENDS, true)) {
            return $row->prior_up === null ? null : "a baseline after the session {$last['event']}";
        }

        if ($row->prior_up === null) {
            return $row->created_at->getTimestamp() - $last['created_at'] > $retention
                ? null
                : 'Redis lost this peer: its next announce had no baseline';
        }

        $priorAt = $row->prior_at?->getTimestamp();

        // A 7.x row's Redis timestamp and created_at were taken apart, so only
        // the bytes are compared across the boundary. A baseline with no time
        // (a recovered one that carried none) credited no seconds, which the
        // arithmetic check holds it to; there is no time to continue.
        $timesMatch = $last['legacy'] || $priorAt === null || $priorAt === $last['created_at'];

        // Continuing the previous row is checked first: announces inside one
        // second share a timestamp, so a matching prior_at alone proves
        // nothing.
        if ($row->prior_up === $last['uploaded'] && $row->prior_down === $last['downloaded'] && $timesMatch) {
            return null;
        }

        if ($priorAt !== null && $last['prior_at'] !== null && $priorAt === $last['prior_at']
            && $row->prior_up === $last['prior_up'] && $row->prior_down === $last['prior_down']) {
            return 'duplicate interval: two rows diffed against the same baseline';
        }

        return 'the baseline does not continue the previous announce';
    }
}
