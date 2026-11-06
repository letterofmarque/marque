<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerAdjustment;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\StaffAction;

/**
 * An operator's correction to a member's totals, on the record (Job #213).
 *
 * Two kinds, one table (ledger_adjustments):
 *
 * - accept(): the stored totals are right and the ledger can't explain them:
 *   figures imported from an earlier tracker, a 4.1.0 install's unexplained
 *   bytes (#11051). The gap is recorded, per torrent from torrent_user, and
 *   what the member holds beyond their torrents on torrent 0. The totals don't
 *   move.
 * - adjust(): give or take a stated amount: a speed forfeit an operator
 *   decides was wrong, whose credit never reached the totals. The adjustment
 *   and the totals move together, the way a fold moves them.
 *
 * Either way reconcile is clean afterwards and a rebuild reproduces it, since
 * both read adjustments (LedgerExtras). Both need a reason and an author who
 * exists, run under the aggregator's cursor lock (a fold landing in between
 * would be counted twice or not at all), and record a staff action.
 */
class LedgerAdjuster
{
    public const TOTALS = ['uploaded', 'downloaded', 'seedtime', 'leechtime'];

    public function __construct(
        private readonly LedgerAggregator $aggregator,
        private readonly LedgerExtras $extras,
        private readonly StaffActionsInterface $staffActions,
    ) {}

    /**
     * What accept() would do for this member, without doing it: the signed gap
     * per torrent and the member-level remainder on torrent 0, or why it would
     * refuse (and then no gaps).
     *
     * @return array{gaps: list<array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}>, refusal: string|null}
     */
    public function preview(int $userId): array
    {
        $measured = $this->measure($userId);

        return ['gaps' => $measured['refusal'] === null ? $measured['gaps'] : [], 'refusal' => $measured['refusal']];
    }

    /**
     * Record the member's stored totals as correct.
     *
     * @return list<LedgerAdjustment> what was written; empty when the ledger
     *                                already explains every total
     */
    public function accept(int $userId, int $by, string $reason): array
    {
        $reason = $this->validate($userId, $by, $reason);

        return $this->locked(function () use ($userId, $by, $reason): array {
            $measured = $this->measure($userId);

            if ($measured['gaps'] === []) {
                return [];
            }

            if ($measured['refusal'] !== null) {
                throw new InvalidArgumentException($measured['refusal']);
            }

            $written = [];

            foreach ($measured['gaps'] as $gap) {
                $written[] = LedgerAdjustment::query()->create($gap + ['reason' => $reason, 'made_by' => $by]);
            }

            // A fold or a rebuild writes member-level credit to a torrent_user
            // row on torrent 0, and reconcile checks that row like any other.
            // Where it exists it moves with the member-level remainder, as a
            // rebuild would move it; the member's own totals don't.
            if ($measured['remainder'] !== null && $measured['zeroRow'] !== null) {
                DB::table('torrent_user')->where('id', $measured['zeroRow'])->incrementEach(
                    array_filter($measured['remainder'], fn (int $amount): bool => $amount !== 0),
                );
            }

            $changes = [];

            foreach (self::TOTALS as $column) {
                $explained = $measured['explained'][$column];
                $now = $explained + array_sum(array_column($measured['gaps'], $column));

                if ($now !== $explained) {
                    $changes["ledger_{$column}"] = ['from' => $explained, 'to' => $now];
                }
            }

            $this->record($by, 'bloodhound.ledger.accepted', $userId, $reason, $changes);

            return $written;
        });
    }

    /**
     * Give or take a stated amount, on one torrent or (torrent 0) the member
     * as a whole.
     *
     * @param  array<string, int>  $amounts  any of uploaded, downloaded, seedtime, leechtime; signed
     */
    public function adjust(int $userId, int $torrentId, array $amounts, int $by, string $reason): LedgerAdjustment
    {
        $reason = $this->validate($userId, $by, $reason);

        $unknown = array_diff(array_keys($amounts), self::TOTALS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Only '.implode(', ', self::TOTALS).' can be adjusted, not '.implode(', ', $unknown).'.');
        }

        $amounts = array_map('intval', $amounts) + array_fill_keys(self::TOTALS, 0);

        if (array_filter($amounts) === []) {
            throw new InvalidArgumentException('An adjustment has to move something: every amount is 0.');
        }

        if ($torrentId !== 0 && ! Torrent::query()->whereKey($torrentId)->exists()) {
            throw new InvalidArgumentException("No torrent has id [{$torrentId}]. Use torrent 0 for the member as a whole.");
        }

        return $this->locked(function () use ($userId, $torrentId, $amounts, $by, $reason): LedgerAdjustment {
            $member = $this->memberTotals($userId);
            $explained = $this->explainedPerTorrent($userId)[$torrentId] ?? [];
            $pair = (array) (DB::table('torrent_user')->where('user_id', $userId)->where('torrent_id', $torrentId)->first(self::TOTALS) ?? []);

            foreach (self::TOTALS as $column) {
                if ($member[$column] + $amounts[$column] < 0) {
                    throw new InvalidArgumentException("That would take the member's {$column} below zero.");
                }

                // Credit held on a torrent is taken from that torrent. Torrent
                // 0 holds only what's on the member as a whole, and a rebuild
                // would write it negative into an unsigned column.
                if ((int) ($pair[$column] ?? 0) + $amounts[$column] < 0) {
                    throw new InvalidArgumentException($torrentId === 0
                        ? "That would take {$column} below zero on torrent 0, the member as a whole: debit the torrent the credit is on instead (--torrent=<id>)."
                        : "That would take {$column} below zero on torrent {$torrentId}.");
                }

                // And on the ledger's side (found by the Job #213 check): a
                // rebuild writes what the ledger explains for the pair, so a
                // debit beyond it would be rebuilt as a negative total. Where
                // the stored total is more than the ledger explains, that
                // gap is accepted first, and then it can be debited.
                if (($explained[$column] ?? 0) + $amounts[$column] < 0) {
                    throw new InvalidArgumentException("That would take what the ledger explains for {$column} on torrent {$torrentId} below zero, and a rebuild would write it as a negative total. If the stored total is right, record it with bloodhound:accept-totals --user={$userId} first.");
                }
            }

            $row = LedgerAdjustment::query()->create(['user_id' => $userId, 'torrent_id' => $torrentId] + $amounts + ['reason' => $reason, 'made_by' => $by]);

            // Written the way a fold writes: the torrent_user row (made if
            // there isn't one) and the member's totals together.
            $this->aggregator->addToTotals([
                $userId.':'.$torrentId => ['user_id' => $userId, 'torrent_id' => $torrentId] + $amounts + ['last_seen' => 0],
            ]);

            $changes = [];

            foreach (self::TOTALS as $column) {
                if ($amounts[$column] !== 0) {
                    $changes[$column] = ['from' => $member[$column], 'to' => $member[$column] + $amounts[$column]];
                }
            }

            $this->record($by, 'bloodhound.ledger.adjusted', $userId, $reason, $changes);

            return $row;
        });
    }

    /**
     * The member's gaps against what the ledger explains, and whether accept()
     * would refuse.
     *
     * @return array{gaps: list<array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}>, remainder: array<string, int>|null, zeroRow: int|null, explained: array<string, int>, refusal: string|null}
     */
    private function measure(int $userId): array
    {
        $zero = array_fill_keys(self::TOTALS, 0);
        $member = $this->memberTotals($userId);
        $expected = $this->explainedPerTorrent($userId);

        $explained = $zero;

        foreach ($expected as $amounts) {
            foreach (self::TOTALS as $column) {
                $explained[$column] += $amounts[$column] ?? 0;
            }
        }

        /** @var array<int, array<string, int>> $gaps per torrent */
        $gaps = [];
        $zeroRow = null;
        $covered = $explained;

        foreach (DB::table('torrent_user')->where('user_id', $userId)->get(array_merge(['id', 'torrent_id'], self::TOTALS)) as $row) {
            $torrent = (int) $row->torrent_id;

            if ($torrent === 0) {
                $zeroRow = (int) $row->id;
            }

            foreach (self::TOTALS as $column) {
                $gap = (int) $row->{$column} - ($expected[$torrent][$column] ?? 0);
                $gaps[$torrent][$column] = $gap;
                $covered[$column] += $gap;
            }
        }

        // What the member's own totals hold beyond every pair's: torrent 0,
        // the precedent the opening balances set.
        $remainder = [];

        foreach (self::TOTALS as $column) {
            $remainder[$column] = $member[$column] - $covered[$column];
        }

        if (array_filter($remainder) === []) {
            $remainder = null;
        } else {
            foreach (self::TOTALS as $column) {
                $gaps[0][$column] = ($gaps[0][$column] ?? 0) + $remainder[$column];
            }
        }

        // A rebuild's job, not an operator's: the member's own totals are
        // below what the ledger explains for their torrents, by more than
        // torrent 0 holds, so the projections contradict each other.
        // Recording it would leave torrent 0's credit negative, which a
        // rebuild writes as a negative torrent_user row, into an unsigned
        // column.
        $refusal = null;

        foreach (self::TOTALS as $column) {
            if (($expected[0][$column] ?? 0) + ($gaps[0][$column] ?? 0) < 0) {
                $refusal = "This member's {$column} is below what the ledger explains for their torrents: the totals contradict each other. Run bloodhound:rebuild-totals --user={$userId} instead.";

                break;
            }
        }

        $written = [];

        foreach ($gaps as $torrent => $amounts) {
            if (array_filter($amounts) === []) {
                continue;
            }

            $written[] = ['user_id' => $userId, 'torrent_id' => $torrent] + $amounts + $zero;
        }

        return ['gaps' => $written, 'remainder' => $remainder, 'zeroRow' => $zeroRow, 'explained' => $explained, 'refusal' => $refusal];
    }

    /**
     * What the ledger explains for each of the member's torrents: folded rows
     * (up to the watermark, as reconcile counts them; a backlog is not drift)
     * plus carry and adjustments. This is what a rebuild would write.
     *
     * @return array<int, array<string, int>>
     */
    private function explainedPerTorrent(int $userId): array
    {
        $expected = [];

        $sums = AnnounceLog::query()
            ->where('id', '<=', LedgerCursor::positionFor(LedgerAggregator::STREAM))
            ->where('user_id', $userId)
            ->groupBy('torrent_id')
            ->selectRaw('torrent_id, SUM(upload_delta) as uploaded, SUM(download_delta) as downloaded, SUM(seed_seconds) as seedtime, SUM(leech_seconds) as leechtime')
            ->toBase()
            ->get();

        foreach ($sums as $row) {
            foreach (self::TOTALS as $column) {
                $expected[(int) $row->torrent_id][$column] = ($expected[(int) $row->torrent_id][$column] ?? 0) + (int) $row->{$column};
            }
        }

        foreach ($this->extras->perPair($userId) as $extra) {
            foreach (self::TOTALS as $column) {
                $expected[$extra['torrent_id']][$column] = ($expected[$extra['torrent_id']][$column] ?? 0) + $extra[$column];
            }
        }

        return $expected;
    }

    /**
     * @return array<string, int>
     */
    private function memberTotals(int $userId): array
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $row = DB::table((new $userModel)->getTable())->where('id', $userId)->first(self::TOTALS);

        if ($row === null) {
            throw new InvalidArgumentException("No member has id [{$userId}].");
        }

        return array_map('intval', (array) $row);
    }

    /**
     * Refuse a correction nobody can account for, before anything is written.
     */
    private function validate(int $userId, int $by, string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Say why: an adjustment needs a reason, kept with it.');
        }

        $userModel = config('trove.user_model', 'App\\Models\\User');

        if (! $userModel::query()->whereKey($by)->exists()) {
            throw new InvalidArgumentException("by must be the deciding admin's user id; no user has id [{$by}].");
        }

        if (! $userModel::query()->whereKey($userId)->exists()) {
            throw new InvalidArgumentException("No member has id [{$userId}].");
        }

        return $reason;
    }

    /**
     * Under the aggregator's cursor lock, in one transaction on each
     * connection: the adjustment is on announce_log's, the totals on the
     * app's, as LedgerBackfill writes carry and totals together.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function locked(callable $work): mixed
    {
        $this->aggregator->ensureCursor();

        return DB::connection((new LedgerAdjustment)->getConnectionName())->transaction(
            fn () => DB::transaction(function () use ($work) {
                $this->aggregator->lockCursor();

                return $work();
            }),
        );
    }

    /**
     * @param  array<string, array{from: int, to: int}>  $changes
     */
    private function record(int $by, string $action, int $userId, string $reason, array $changes): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');

        $this->staffActions->record(new StaffAction(
            actorId: $by,
            action: $action,
            subjectType: (new $userModel)->getMorphClass(),
            subjectId: $userId,
            reason: $reason,
            changes: $changes,
        ));
    }
}
