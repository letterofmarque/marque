<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Marque\Bloodhound\Models\AnnounceLog;

/**
 * How long a peer spent seeding and leeching, derived from the ledger.
 *
 * The one place that knows how each engine measures the time between two
 * announces. The aggregator and the rebuilder both read seedtime through this;
 * two copies of an engine matrix is how they drift, and drift here would mean
 * seedtime computed one way live and another on rebuild — which the audit
 * would then report as corruption that never happened.
 *
 * An interval is the gap between two consecutive announces from one peer on
 * one torrent. It is:
 *
 * - attributed by the peer's state at its START: `left = 0` is seeding,
 *   `left > 0` is leeching. The interval in which a peer completes is leech
 *   time, because that is what it spent it doing.
 * - capped at `threepio.peer_expiry`, the line past which the swarm already
 *   considers a peer gone. A late announce credits the cap, not zero.
 * - never opened by a `stopped` row. The peer told us it left; the gap until
 *   it comes back is not time on the swarm.
 *
 * Each interval is credited on the row that CLOSES it (LAG, looking back),
 * not the row that opens it. Spec #121 sketches it with LEAD; the totals are
 * identical, but crediting on the closing row means a consumer folding the
 * ledger by id range credits every interval exactly once, whichever side of a
 * batch boundary its two rows land on. Looking forward, the opening row is
 * folded before the closing row exists.
 *
 * See Spec #121.
 */
final class SessionTime
{
    private const DRIVERS = ['sqlite', 'mysql', 'mariadb', 'pgsql'];

    /**
     * Previous-announce lookups per UNION ALL statement. Five bindings each,
     * well inside every engine's placeholder limit (SQLite's is the lowest).
     */
    private const LOOKUPS_PER_STATEMENT = 200;

    public function __construct(
        private readonly string $driver,
        private readonly int $cap,
    ) {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException(
                "Seedtime cannot be derived on the '{$driver}' database driver. Supported: ".implode(', ', self::DRIVERS).'.'
            );
        }
    }

    /**
     * For the connection announce_log actually lives on.
     *
     * Not the app's default: an operator can point announce_log at a separate
     * database, possibly a different engine (Spec #98).
     */
    public static function forLedger(): self
    {
        $connection = DB::connection((new AnnounceLog)->getConnectionName());

        return new self(
            $connection->getDriverName(),
            (int) config('threepio.peer_expiry', 3600),
        );
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function cap(): int
    {
        return $this->cap;
    }

    /**
     * Whole seconds from one timestamp expression to another.
     */
    public function secondsBetween(string $earlier, string $later): string
    {
        return match ($this->driver) {
            'sqlite' => "(strftime('%s', {$later}) - strftime('%s', {$earlier}))",
            'mysql', 'mariadb' => "TIMESTAMPDIFF(SECOND, {$earlier}, {$later})",
            'pgsql' => "CAST(EXTRACT(EPOCH FROM ({$later} - {$earlier})) AS BIGINT)",
        };
    }

    /**
     * The seconds an interval earns: its length, capped, and never negative.
     *
     * Negative only if a clock stepped backwards between two announces, but
     * then it is not merely wrong — adding it to an unsigned column fails.
     */
    public function credited(string $earlier, string $later): string
    {
        $seconds = $this->secondsBetween($earlier, $later);

        return match ($this->driver) {
            'sqlite' => "MAX(0, MIN({$seconds}, {$this->cap}))",
            default => "GREATEST(0, LEAST({$seconds}, {$this->cap}))",
        };
    }

    /**
     * One row per ledger row with id in ($after, $through]: id, user_id,
     * torrent_id, and the seed_seconds / leech_seconds credited for the
     * interval that row closes.
     *
     * Each interval is credited by the row that closes it, so folding
     * consecutive id ranges credits every interval exactly once. A row's
     * opening partner may sit at or below $after, folded long ago. It is
     * reached back for, one index seek per peer (openingIds()), rather than by
     * windowing the peer's whole history. Windowing it would give the same
     * answer, but a seeder of a year's standing has 17,000 announces behind it.
     *
     * `$scope` narrows which peers are credited (a user, for a scoped
     * rebuild). Constrain only on what the pairing partitions by: user_id,
     * torrent_id, peer_id. Anything else can cut a row out of its own pair.
     *
     * @param  (callable(Builder): mixed)|null  $scope
     */
    public function intervals(int $after = 0, ?int $through = null, ?callable $scope = null): Builder
    {
        $connection = DB::connection((new AnnounceLog)->getConnectionName());
        $grammar = $connection->getQueryGrammar();

        // `left` is a reserved word on MySQL and MariaDB.
        $left = $grammar->wrap('left');
        $over = 'OVER (PARTITION BY user_id, torrent_id, peer_id ORDER BY id)';

        $inRange = fn (Builder $query, string $column) => $query
            ->where($column, '>', $after)
            ->when($through !== null, fn (Builder $query) => $query->where($column, '<=', $through));

        $rows = fn () => $connection->table('announce_log')
            ->select(['id', 'user_id', 'torrent_id', 'peer_id', 'event', 'left', 'created_at'])
            // A carried-forward balance, not an announce: it says nothing
            // about when anyone was connected.
            ->where('event', '!=', AnnounceLog::EVENT_OPENING_BALANCE)
            ->when($scope !== null, fn (Builder $query) => $scope($query));

        $source = $rows()->where(fn (Builder $query) => $inRange($query, 'id'));

        // UNION ALL, not `id in range OR id IN (...)`. Measured on 200k rows:
        // with the OR, MySQL and PostgreSQL abandoned the primary key and
        // scanned the whole ledger for every batch. Each branch alone is an
        // index lookup. The branches cannot overlap, since every opening row
        // is at or below $after and every in-range row above it.
        if ($after > 0) {
            $openings = $this->openingIds($connection, $source->clone(), $after);

            if ($openings !== []) {
                $source->unionAll($rows()->whereIn('id', $openings));
            }
        }

        $pairs = $connection->query()
            ->fromSub($source, 'ledger')
            ->select(['id', 'user_id', 'torrent_id', 'created_at'])
            ->selectRaw("LAG(created_at) {$over} as prev_at")
            ->selectRaw("LAG({$left}) {$over} as prev_left")
            ->selectRaw("LAG(event) {$over} as prev_event");

        $credited = $this->credited('prev_at', 'created_at');
        $open = "prev_at IS NOT NULL AND prev_event <> 'stopped'";

        $intervals = $connection->query()
            ->fromSub($pairs, 'pairs')
            ->select(['id', 'user_id', 'torrent_id'])
            ->selectRaw("CASE WHEN {$open} AND prev_left = 0 THEN {$credited} ELSE 0 END as seed_seconds")
            ->selectRaw("CASE WHEN {$open} AND prev_left > 0 THEN {$credited} ELSE 0 END as leech_seconds");

        // Wrapped once more so a consumer can filter, group and SUM on
        // seed_seconds / leech_seconds — a column alias is not visible to the
        // WHERE or aggregate of the query that defines it. The opening rows
        // reached back for are in the window only to be paired with; they
        // were credited when they were folded, so they are filtered out here.
        return $connection->query()
            ->fromSub($intervals, 'intervals')
            ->where(fn (Builder $query) => $inRange($query, 'id'));
    }

    /**
     * For each peer announcing in range, the id of its last announce at or
     * below $after: the row that opens the interval its first in-range row
     * closes.
     *
     * Deliberately not a correlated subquery. Written as one, MySQL 8.0.44
     * returned each peer's FIRST announce rather than its latest at scale, a
     * wrong answer that depended on the plan and passed on small fixtures;
     * MariaDB, PostgreSQL and SQLite were right. So the peers are read first
     * (at most one batch of keys, never ledger history), and each is looked up
     * with the query the announce path already runs on every Redis miss
     * (BloodhoundServiceProvider::recoverBaselineFromLedger): literal values,
     * ORDER BY id DESC LIMIT 1, one seek on announce_log_peer_baseline_index.
     * Chunked into UNION ALL statements so a batch is a few round trips.
     *
     * @return list<int>
     */
    private function openingIds(Connection $connection, Builder $batch, int $after): array
    {
        $peers = $batch
            ->select(['user_id', 'torrent_id', 'peer_id'])
            ->distinct()
            ->get();

        $ids = [];

        foreach ($peers->chunk(self::LOOKUPS_PER_STATEMENT) as $chunk) {
            $union = null;

            foreach ($chunk as $peer) {
                $lookup = $connection->table('announce_log')
                    ->select('id')
                    ->where('torrent_id', $peer->torrent_id)
                    ->where('peer_id', $peer->peer_id)
                    ->where('user_id', $peer->user_id)
                    ->where('id', '<=', $after)
                    ->where('event', '!=', AnnounceLog::EVENT_OPENING_BALANCE)
                    ->orderByDesc('id')
                    ->limit(1);

                $union = $union === null ? $lookup : $union->unionAll($lookup);
            }

            foreach ($union?->pluck('id') ?? [] as $id) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
