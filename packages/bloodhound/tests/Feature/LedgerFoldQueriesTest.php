<?php

declare(strict_types=1);

// Job #112, correction to CP #651: a batch folds in a fixed number of
// statements, however many users and torrents it touches.
//
// CP #651 made nearly every announce credit time, and the fold wrote each
// (user, torrent) with its own SELECT and UPDATE and each user with its own
// UPDATE: 498k queries to aggregate 200k rows, against 101k before. On a busy
// tracker that is the difference between keeping up and falling behind.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Tests\TestUser;

/**
 * One announce per peer for $pairs (user, torrent) pairs, at $minutes past a
 * fixed start: every pair gets bytes, so every pair is written. Each of the
 * $pairs credits $seed seconds; an extra user is a first announce, crediting
 * none.
 */
function foldRound(int $pairs, int $minutes, int $extraUser = 0, int $seed = 0): void
{
    for ($i = 1; $i <= $pairs + $extraUser; $i++) {
        $row = AnnounceLog::create([
            'user_id' => $i,
            'torrent_id' => $i,
            'peer_id' => sprintf('-qB4210-%012d', $i),
            'event' => 'regular',
            'ip' => '10.0.0.1',
            'port' => 51413,
            'uploaded' => 100,
            'downloaded' => 0,
            'left' => 0,
            'upload_delta' => 100,
            'download_delta' => 0,
            'seed_seconds' => $i <= $pairs ? $seed : 0,
        ]);

        $row->forceFill(['created_at' => Carbon::parse('2026-10-01 10:00:00')->addMinutes($minutes)])->save();
    }
}

/**
 * Queries the second fold makes: existing pairs to update, one new pair to
 * insert, every user's totals to increment.
 */
function queriesToFold(int $pairs): int
{
    DB::table('announce_log')->delete();
    DB::table('torrent_user')->delete();
    DB::table('ledger_cursors')->where('stream', LedgerAggregator::STREAM)->delete();
    DB::table((new TestUser)->getTable())->delete();

    for ($i = 1; $i <= $pairs + 1; $i++) {
        DB::table((new TestUser)->getTable())->insert(['id' => $i, 'name' => "u{$i}", 'email' => "fold{$i}@example.com", 'password' => 'p']);
    }

    foldRound($pairs, 0);
    app(LedgerAggregator::class)->run();

    foldRound($pairs, 30, extraUser: 1, seed: 1_800);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(LedgerAggregator::class)->run();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('a batch folds in the same number of queries whatever it touches', function () {
    expect(queriesToFold(2))->toBe(queriesToFold(60));
});

test('the set-based fold credits exactly what the per-pair one did', function () {
    queriesToFold(60);

    // Two rounds of 100 bytes each, 30 minutes apart, for each of 60 pairs;
    // the 61st pair announced once, so 100 bytes and no interval yet.
    $rows = TorrentUser::query()->orderBy('user_id')->get();

    expect($rows)->toHaveCount(61)
        ->and($rows->take(60)->every(fn ($row) => $row->uploaded === 200 && $row->seedtime === 1800))->toBeTrue()
        ->and([$rows->last()->uploaded, $rows->last()->seedtime])->toBe([100, 0])
        ->and((int) DB::table((new TestUser)->getTable())->sum('uploaded'))->toBe(60 * 200 + 100)
        ->and((int) DB::table((new TestUser)->getTable())->sum('seedtime'))->toBe(60 * 1800);
});

// The fold's increments are applied by the database to the stored value, not
// computed in PHP from a value read earlier: a total far past 32 bits must
// survive it intact (PostgreSQL would type a bare CASE ... ELSE 0 as int4).
test('totals past 32 bits fold intact', function () {
    $user = TestUser::create(['name' => 'big', 'email' => 'big@example.com', 'password' => 'p']);
    $big = 5_000_000_000;

    foreach ([0, 30] as $minutes) {
        $row = AnnounceLog::create([
            'user_id' => $user->id, 'torrent_id' => 1, 'peer_id' => '-qB4210-bigbigbigbig', 'event' => 'regular',
            'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => $big, 'downloaded' => 0, 'left' => 0,
            'upload_delta' => $big, 'download_delta' => 0,
        ]);
        $row->forceFill(['created_at' => Carbon::parse('2026-10-01 10:00:00')->addMinutes($minutes)])->save();
        app(LedgerAggregator::class)->run();
    }

    expect(TorrentUser::query()->first()->uploaded)->toBe(2 * $big)
        ->and($user->fresh()->uploaded)->toBe(2 * $big);
});
