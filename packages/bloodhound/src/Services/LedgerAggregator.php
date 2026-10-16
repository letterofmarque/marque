<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Support\BatchIncrement;
use Marque\Bloodhound\Support\SessionTime;

/**
 * Folds ledger rows into the totals the site reads.
 *
 * Replaces the payload-carrying UpdateUserStats job. That design put the only
 * copy of a byte count inside a queued job: lose the job and the credit was
 * gone, unrecoverable because Redis had already advanced the baseline it came
 * from. On a typical Laravel box the queue is Redis too, so a single restart
 * could take both.
 *
 * Here the byte counts never move through the queue. They are read from the
 * ledger — the durable record written on the announce path — starting from a
 * stored watermark. The queue only decides *when* this runs, so it can drop
 * every job it holds and lose nothing: aggregation is late, not wrong.
 *
 * Crash-safety is the transaction boundary. The projection write and the
 * cursor advance commit together or not at all, which makes a redone batch
 * idempotent by construction rather than by a dedupe check.
 *
 * See Spec #99.
 */
class LedgerAggregator
{
    /**
     * The cursor stream this aggregator owns.
     *
     * Named so a later projection can consume the same ledger on its own
     * cursor without interfering.
     */
    public const STREAM = 'user_totals';

    /**
     * The four totals a fold adds to, on torrent_user and on the user.
     */
    private const TOTALS = ['uploaded', 'downloaded', 'seedtime', 'leechtime'];

    /**
     * Rows per write statement. An insert binds 9 values per row and an
     * increment 5, so 1,000 rows stays under 9,001 placeholders: inside
     * SQLite's 32,766 and MySQL's and PostgreSQL's 65,535. (SQLite before
     * 3.32 allowed 999; bloodhound's README states the floor, which UPDATE
     * ... FROM raises to 3.33 anyway.)
     */
    private const ROWS_PER_STATEMENT = 1000;

    /**
     * Fold everything the ledger has that this stream has not yet counted.
     *
     * Loops until caught up rather than processing a single batch, so a
     * backlog drains in one run instead of needing as many ticks as batches.
     *
     * @return int rows folded
     */
    public function run(int $batchSize = 1000): int
    {
        $total = 0;

        while (($folded = $this->foldNextBatch($batchSize)) > 0) {
            $total += $folded;
        }

        return $total;
    }

    /**
     * Fold one batch, advancing the cursor in the same transaction.
     *
     * @return int rows folded (0 means caught up)
     */
    protected function foldNextBatch(int $batchSize): int
    {
        $position = LedgerCursor::positionFor(self::STREAM);

        $rows = AnnounceLog::query()
            ->where('id', '>', $position)
            ->orderBy('id')
            ->limit($batchSize)
            ->get(['id', 'user_id', 'torrent_id', 'upload_delta', 'download_delta']);

        if ($rows->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($rows, $position) {
            $this->applyDeltas($rows, $this->sessionTimes($position, $rows->last()->id));

            // Inside the transaction, deliberately. If this fails, the writes
            // above roll back with it and the batch is simply redone.
            $this->advanceCursor($rows->last()->id);
        });

        return $rows->count();
    }

    /**
     * Seconds seeded and leeched per (user, torrent), for the intervals the
     * rows in ($after, $through] close (Spec #121).
     *
     * Summed in the database. An interval whose opening row was folded by an
     * earlier batch or run is reached back for there, and credited here once,
     * by the row that closes it.
     *
     * @return array<string, array{user_id: int, torrent_id: int, seedtime: int, leechtime: int}>
     */
    protected function sessionTimes(int $after, int $through): array
    {
        $sums = SessionTime::forLedger()->intervals($after, $through)
            ->groupBy('user_id', 'torrent_id')
            ->selectRaw('user_id, torrent_id, SUM(seed_seconds) as seed, SUM(leech_seconds) as leech')
            ->get();

        $times = [];

        foreach ($sums as $sum) {
            $times[$sum->user_id.':'.$sum->torrent_id] = [
                'user_id' => (int) $sum->user_id,
                'torrent_id' => (int) $sum->torrent_id,
                'seedtime' => (int) $sum->seed,
                'leechtime' => (int) $sum->leech,
            ];
        }

        return $times;
    }

    /**
     * Add each row's deltas, and the batch's session times, to the totals
     * they belong to.
     *
     * Grouped per (user, torrent) so a batch of many announces from one peer
     * is a single write rather than one per row.
     *
     * @param  Collection<int, AnnounceLog>  $rows
     * @param  array<string, array{user_id: int, torrent_id: int, seedtime: int, leechtime: int}>  $times
     */
    protected function applyDeltas($rows, array $times): void
    {
        $perTorrent = [];

        foreach ($rows as $row) {
            // Skipped for BYTES only. An idle seeder's announce and every
            // `stopped` move no bytes, and are exactly what seedtime is made
            // of, so they reach the time sums above; here they must still
            // contribute nothing, or every announce that moved nothing would
            // write a torrent_user row.
            if ($row->upload_delta === 0 && $row->download_delta === 0) {
                continue;
            }

            $key = $row->user_id.':'.$row->torrent_id;

            $perTorrent[$key] ??= $this->emptyEntry($row->user_id, $row->torrent_id);
            $perTorrent[$key]['uploaded'] += $row->upload_delta;
            $perTorrent[$key]['downloaded'] += $row->download_delta;
        }

        foreach ($times as $key => $time) {
            // A pair that closed with nothing to credit (a stopped row's
            // successor, a first announce) is not activity either.
            if ($time['seedtime'] === 0 && $time['leechtime'] === 0) {
                continue;
            }

            $perTorrent[$key] ??= $this->emptyEntry($time['user_id'], $time['torrent_id']);
            $perTorrent[$key]['seedtime'] += $time['seedtime'];
            $perTorrent[$key]['leechtime'] += $time['leechtime'];
        }

        $perUser = [];

        foreach ($perTorrent as $entry) {
            $perUser[$entry['user_id']] ??= ['uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];

            foreach (self::TOTALS as $column) {
                $perUser[$entry['user_id']][$column] += $entry[$column];
            }
        }

        $this->addToTorrentUsers($perTorrent);
        $this->addToUsers($perUser);
    }

    /**
     * @return array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}
     */
    protected function emptyEntry(int $userId, int $torrentId): array
    {
        return [
            'user_id' => $userId,
            'torrent_id' => $torrentId,
            'uploaded' => 0,
            'downloaded' => 0,
            'seedtime' => 0,
            'leechtime' => 0,
        ];
    }

    /**
     * Write a batch's per-torrent totals: one select, one insert, one update.
     *
     * Not a SELECT and an UPDATE per (user, torrent). Once every announce
     * earned time (Spec #121), that pattern cost 498k queries to fold 200k
     * rows. A batch now costs the same handful of statements whatever it
     * touches (chunked under every engine's placeholder limit).
     *
     * @param  array<string, array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}>  $perTorrent
     */
    protected function addToTorrentUsers(array $perTorrent): void
    {
        if ($perTorrent === []) {
            return;
        }

        // A superset (every user in the batch on every torrent in it),
        // narrowed to the exact pairs in PHP: two plain IN lists rather than
        // a tuple comparison not every engine supports.
        $existing = DB::table('torrent_user')
            ->whereIn('user_id', array_unique(array_column($perTorrent, 'user_id')))
            ->whereIn('torrent_id', array_unique(array_column($perTorrent, 'torrent_id')))
            ->get(['id', 'user_id', 'torrent_id'])
            ->keyBy(fn ($row) => $row->user_id.':'.$row->torrent_id);

        $inserts = [];
        $increments = [];

        foreach ($perTorrent as $key => $entry) {
            $row = $existing->get($key);

            if ($row === null) {
                $inserts[] = [
                    'user_id' => $entry['user_id'],
                    'torrent_id' => $entry['torrent_id'],
                    'uploaded' => $entry['uploaded'],
                    'downloaded' => $entry['downloaded'],
                    'seedtime' => $entry['seedtime'],
                    'leechtime' => $entry['leechtime'],
                    'times_completed' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                continue;
            }

            $increments[(int) $row->id] = $entry;
        }

        foreach (array_chunk($inserts, self::ROWS_PER_STATEMENT) as $chunk) {
            DB::table('torrent_user')->insert($chunk);
        }

        $this->incrementByKey('torrent_user', $increments, touch: true);
    }

    /**
     * Kept as a maintained column rather than derived on read (Spec #99 Open
     * Question 3): ratio is read on effectively every page, and it is now
     * reconcilable against SUM(torrent_user) and the ledger itself — which is
     * what it never was as a bare accumulator.
     *
     * @param  array<int, array{uploaded: int, downloaded: int, seedtime: int, leechtime: int}>  $perUser
     */
    protected function addToUsers(array $perUser): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');

        $this->incrementByKey((new $userModel)->getTable(), $perUser, touch: false);
    }

    /**
     * Add each id's amounts to its stored totals, one statement per chunk.
     *
     * See BatchIncrement for why it is a join against an inline table, and
     * for the two ways the engines spell that.
     *
     * @param  array<int, array{uploaded: int, downloaded: int, seedtime: int, leechtime: int}>  $amountsById
     */
    protected function incrementByKey(string $table, array $amountsById, bool $touch): void
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $increment = new BatchIncrement($connection->getDriverName());

        foreach (array_chunk($amountsById, self::ROWS_PER_STATEMENT, preserve_keys: true) as $chunk) {
            [$sql, $bindings] = $increment->statement(
                $grammar->wrapTable($table),
                $grammar->wrap('id'),
                array_map(fn ($column) => $grammar->wrap($column), self::TOTALS),
                self::TOTALS,
                $chunk,
                $touch ? [$grammar->wrap('updated_at'), now()] : null,
            );

            $connection->update($sql, $bindings);
        }
    }

    protected function advanceCursor(int $position): void
    {
        $cursor = LedgerCursor::query()->where('stream', self::STREAM)->first();

        if ($cursor === null) {
            LedgerCursor::create(['stream' => self::STREAM, 'position' => $position]);

            return;
        }

        $cursor->position = $position;
        $cursor->save();
    }
}
