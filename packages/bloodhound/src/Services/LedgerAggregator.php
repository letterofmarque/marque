<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
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
            $this->addToTorrentUser($entry);

            $perUser[$entry['user_id']] ??= ['uploaded' => 0, 'downloaded' => 0, 'seedtime' => 0, 'leechtime' => 0];

            foreach (['uploaded', 'downloaded', 'seedtime', 'leechtime'] as $column) {
                $perUser[$entry['user_id']][$column] += $entry[$column];
            }
        }

        foreach ($perUser as $userId => $totals) {
            $this->addToUser((int) $userId, $totals);
        }
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
     * @param  array{user_id: int, torrent_id: int, uploaded: int, downloaded: int, seedtime: int, leechtime: int}  $entry
     */
    protected function addToTorrentUser(array $entry): void
    {
        $existing = DB::table('torrent_user')
            ->where('user_id', $entry['user_id'])
            ->where('torrent_id', $entry['torrent_id'])
            ->first();

        if ($existing === null) {
            DB::table('torrent_user')->insert([
                'user_id' => $entry['user_id'],
                'torrent_id' => $entry['torrent_id'],
                'uploaded' => $entry['uploaded'],
                'downloaded' => $entry['downloaded'],
                'seedtime' => $entry['seedtime'],
                'leechtime' => $entry['leechtime'],
                'times_completed' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('torrent_user')
            ->where('id', $existing->id)
            ->update([
                'uploaded' => $existing->uploaded + $entry['uploaded'],
                'downloaded' => $existing->downloaded + $entry['downloaded'],
                'seedtime' => $existing->seedtime + $entry['seedtime'],
                'leechtime' => $existing->leechtime + $entry['leechtime'],
                'updated_at' => now(),
            ]);
    }

    /**
     * Kept as a maintained column rather than derived on read (Spec #99 Open
     * Question 3): ratio is read on effectively every page, and it is now
     * reconcilable against SUM(torrent_user) and the ledger itself — which is
     * what it never was as a bare accumulator.
     *
     * @param  array{uploaded: int, downloaded: int, seedtime: int, leechtime: int}  $totals
     */
    protected function addToUser(int $userId, array $totals): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $table = (new $userModel)->getTable();

        // incrementEach() rather than DB::raw("uploaded + {$delta}") as the old
        // job did — atomic, no string interpolation into SQL, and portable
        // across the engines Marque supports.
        DB::table($table)
            ->where('id', $userId)
            ->incrementEach($totals);
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
