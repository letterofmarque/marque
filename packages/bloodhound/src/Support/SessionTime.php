<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

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
     * One row per ledger row: id, user_id, torrent_id, and the seed_seconds /
     * leech_seconds credited for the interval that row closes.
     *
     * `$scope` narrows the rows the pairing sees. Constrain only on what the
     * pairing partitions by (user_id, torrent_id, peer_id) or by an UPPER
     * bound on id. A lower bound on id would cut a peer's earlier announce
     * out of the window, and the first row above it would pair with nothing.
     * To fold a range of ids, filter the returned query instead.
     *
     * @param  (callable(Builder): mixed)|null  $scope
     */
    public function intervals(?callable $scope = null): Builder
    {
        $connection = DB::connection((new AnnounceLog)->getConnectionName());
        $grammar = $connection->getQueryGrammar();

        // `left` is a reserved word on MySQL and MariaDB.
        $left = $grammar->wrap('left');
        $over = 'OVER (PARTITION BY user_id, torrent_id, peer_id ORDER BY id)';

        $pairs = $connection->table('announce_log')
            ->select(['id', 'user_id', 'torrent_id', 'created_at'])
            ->selectRaw("LAG(created_at) {$over} as prev_at")
            ->selectRaw("LAG({$left}) {$over} as prev_left")
            ->selectRaw("LAG(event) {$over} as prev_event")
            // A carried-forward balance, not an announce: it says nothing
            // about when anyone was connected.
            ->where('event', '!=', AnnounceLog::EVENT_OPENING_BALANCE)
            ->when($scope !== null, fn (Builder $query) => $scope($query));

        $credited = $this->credited('prev_at', 'created_at');
        $open = "prev_at IS NOT NULL AND prev_event <> 'stopped'";

        $intervals = $connection->query()
            ->fromSub($pairs, 'pairs')
            ->select(['id', 'user_id', 'torrent_id'])
            ->selectRaw("CASE WHEN {$open} AND prev_left = 0 THEN {$credited} ELSE 0 END as seed_seconds")
            ->selectRaw("CASE WHEN {$open} AND prev_left > 0 THEN {$credited} ELSE 0 END as leech_seconds");

        // Wrapped once more so a consumer can filter, group and SUM on
        // seed_seconds / leech_seconds — a column alias is not visible to the
        // WHERE or aggregate of the query that defines it.
        return $connection->query()->fromSub($intervals, 'intervals');
    }
}
