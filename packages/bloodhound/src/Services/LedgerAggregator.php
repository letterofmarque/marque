<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Support\BatchIncrement;
use RuntimeException;

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
     * Rows per write statement. An insert binds 10 values per row and an
     * increment of torrent_user 6, so 1,000 rows stays under 10,001 placeholders: inside
     * SQLite's 32,766 and MySQL's and PostgreSQL's 65,535. (SQLite before
     * 3.32 allowed 999; bloodhound's README states the floor, which UPDATE
     * ... FROM raises to 3.33 anyway.)
     */
    private const ROWS_PER_STATEMENT = 1000;

    /**
     * Whether torrent_user has last_seen yet. Upgrade migrations fold the
     * ledger before the one that adds it has run, so a fold must not name a
     * column that isn't there. Asked once per aggregator.
     */
    private ?bool $hasLastSeen = null;

    /**
     * Fold everything the ledger has that this stream has not yet counted.
     *
     * To the head of the ledger: for the migrations that must leave totals
     * folded before they finish, and for catching up by hand. The scheduler
     * uses runScheduled(), which stops at the horizon.
     *
     * @return int rows folded
     */
    public function run(int $batchSize = 1000): int
    {
        return $this->foldThrough(null, $batchSize);
    }

    /**
     * The scheduled fold: only as far as the highest id the previous run saw.
     *
     * An id is handed out when an insert starts, not when it commits, so a
     * row can become visible after a later id already has. Folding to the
     * head could move the cursor past it, and it would never be counted
     * (F12). Every row below the horizon has had a whole tick to commit. The
     * cost is that totals trail the ledger by one to two runs, and a backlog
     * drains in two.
     *
     * @return int rows folded
     */
    public function runScheduled(int $batchSize = 1000): int
    {
        $this->ensureCursor();

        $horizon = (int) DB::table('ledger_cursors')->where('stream', self::STREAM)->value('horizon');
        $folded = $this->foldThrough($horizon, $batchSize);

        $head = (int) AnnounceLog::query()->max('id');

        DB::table('ledger_cursors')
            ->where('stream', self::STREAM)
            ->where('horizon', '<', $head)
            ->update(['horizon' => $head, 'updated_at' => now()]);

        return $folded;
    }

    /**
     * Fold batch after batch until caught up to $through (null: the head).
     */
    protected function foldThrough(?int $through, int $batchSize): int
    {
        $this->ensureCursor();

        $total = 0;

        while (($folded = $this->foldNextBatch($batchSize, $through)) > 0) {
            $total += $folded;
        }

        return $total;
    }

    /**
     * Fold one batch, advancing the cursor in the same transaction.
     *
     * The cursor row is locked first, and the position read under the lock,
     * so a second fold (the scheduler and a manual run, two hosts without a
     * shared cache lock, a migration) waits rather than folding the same
     * rows. A no-op UPDATE rather than SELECT ... FOR UPDATE: it is the one
     * spelling that takes a write lock on every engine, SQLite included,
     * where it takes the database's.
     *
     * The advance is a compare-and-swap on the position just read. If
     * anything moved the cursor in between, the batch rolls back and is
     * redone from wherever it now is (F11).
     *
     * @return int rows folded (0 means caught up)
     */
    protected function foldNextBatch(int $batchSize, ?int $through = null): int
    {
        return DB::transaction(function () use ($batchSize, $through) {
            $this->lockCursor();

            $position = LedgerCursor::positionFor(self::STREAM);

            $rows = AnnounceLog::query()
                ->where('id', '>', $position)
                ->when($through !== null, fn ($query) => $query->where('id', '<=', $through))
                ->orderBy('id')
                ->limit($batchSize)
                ->get(['id', 'user_id', 'torrent_id', 'upload_delta', 'download_delta', 'seed_seconds', 'leech_seconds', 'created_at']);

            if ($rows->isEmpty()) {
                return 0;
            }

            $this->applyDeltas($rows);

            // Inside the transaction, deliberately. If this fails, the writes
            // above roll back with it and the batch is simply redone.
            $this->advanceCursor($position, $rows->last()->id);

            return $rows->count();
        });
    }

    /**
     * Take the cursor row's lock for the rest of the transaction.
     *
     * Also what a rebuild takes, so the two never interleave.
     */
    public function lockCursor(): void
    {
        DB::table('ledger_cursors')
            ->where('stream', self::STREAM)
            ->update(['position' => DB::raw(DB::getQueryGrammar()->wrap('position'))]);
    }

    /**
     * The cursor row exists before anything locks it: a lock on no row
     * protects nothing.
     */
    public function ensureCursor(): void
    {
        DB::table('ledger_cursors')->insertOrIgnore([
            'stream' => self::STREAM,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Add each row's credit (bytes and seconds) to the totals it belongs to.
     *
     * Every row carries its own credit, written at announce time, so a fold
     * is a sum: nothing pairs one row with another, and a batch boundary
     * splits nothing.
     *
     * Grouped per (user, torrent) so a batch of many announces from one peer
     * is a single write rather than one per row.
     *
     * @param  Collection<int, AnnounceLog>  $rows
     */
    protected function applyDeltas($rows): void
    {
        $perTorrent = [];

        foreach ($rows as $row) {
            // A row that credits nothing (a refusal, a first announce) is not
            // activity, and writes no torrent_user row.
            if ($row->upload_delta === 0 && $row->download_delta === 0
                && $row->seed_seconds === 0 && $row->leech_seconds === 0) {
                continue;
            }

            $key = $row->user_id.':'.$row->torrent_id;

            $perTorrent[$key] ??= $this->emptyEntry($row->user_id, $row->torrent_id);
            $perTorrent[$key]['uploaded'] += $row->upload_delta;
            $perTorrent[$key]['downloaded'] += $row->download_delta;
            $perTorrent[$key]['seedtime'] += $row->seed_seconds;
            $perTorrent[$key]['leechtime'] += $row->leech_seconds;

            // When the member was last seen on this torrent (Job #256): the
            // newest stamp in the batch. The write keeps the larger of this
            // and what is stored, so an older row folding late moves nothing.
            $perTorrent[$key]['last_seen'] = max($perTorrent[$key]['last_seen'], $row->created_at?->getTimestamp() ?? 0);
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
     * Add amounts to the totals directly: credit that is not a ledger row's
     * (carry recorded by an upgrade step), written the way a fold writes.
     * The caller holds the cursor lock.
     *
     * @param  array<string, array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}>  $perTorrent
     */
    public function addToTotals(array $perTorrent): void
    {
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
            // 0: nothing to say (carry is not activity). Never written.
            'last_seen' => 0,
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

        if ($this->hasLastSeen()) {
            foreach ($inserts as $i => $insert) {
                $seen = $perTorrent[$insert['user_id'].':'.$insert['torrent_id']]['last_seen'] ?? 0;
                $inserts[$i]['last_seen'] = $seen > 0 ? $seen : null;
            }
        }

        foreach (array_chunk($inserts, self::ROWS_PER_STATEMENT) as $chunk) {
            DB::table('torrent_user')->insert($chunk);
        }

        $this->incrementByKey('torrent_user', $increments, touch: true, keepLarger: $this->hasLastSeen() ? ['last_seen'] : []);
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
    protected function incrementByKey(string $table, array $amountsById, bool $touch, array $keepLarger = []): void
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
                array_map(fn ($column) => $grammar->wrap($column), $keepLarger),
                $keepLarger,
            );

            $connection->update($sql, $bindings);
        }
    }

    private function hasLastSeen(): bool
    {
        return $this->hasLastSeen ??= Schema::hasColumn('torrent_user', 'last_seen');
    }

    protected function advanceCursor(int $from, int $to): void
    {
        $moved = DB::table('ledger_cursors')
            ->where('stream', self::STREAM)
            ->where('position', $from)
            ->update(['position' => $to, 'updated_at' => now()]);

        if ($moved !== 1) {
            throw new RuntimeException(
                "The ledger cursor moved from {$from} while this batch was being folded; the batch was rolled back and will be redone."
            );
        }
    }
}
