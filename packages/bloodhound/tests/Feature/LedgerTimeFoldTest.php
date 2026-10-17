<?php

declare(strict_types=1);

// Job #112 CP2 (Spec #121), reworked for Job #193: the aggregator folds
// seedtime and leechtime.
//
// Each row carries the seconds its announce was credited, so folding time is
// a sum, like bytes. What still has to hold: a row that credits only time (an
// idle seeder's announce, a stop) reaches the totals and moves no bytes; a
// row that credits nothing writes no torrent_user row; and how the ledger is
// cut into batches changes nothing.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::create([
        'name' => 'Test User',
        'email' => 'time@example.com',
        'password' => 'password',
    ]);

    $this->torrent = Torrent::create([
        'name' => 'Test Torrent',
        'info_hash' => str_repeat('a', 40),
        'size' => 5_000_000_000,
        'user_id' => $this->user->id,
    ]);

    $this->aggregator = app(LedgerAggregator::class);
    $this->t = Carbon::parse('2026-10-01 10:00:00');
});

/**
 * One announce, at a controlled time, crediting what it is given.
 */
function timedRow(
    $test,
    string $event,
    int $minutes,
    int $up = 0,
    int $down = 0,
    int $seed = 0,
    int $leech = 0,
    ?int $torrentId = null,
): AnnounceLog {
    $row = AnnounceLog::create([
        'user_id' => $test->user->id,
        'torrent_id' => $torrentId ?? $test->torrent->id,
        'peer_id' => '-qB4210-aaaaaaaaaaaa',
        'event' => $event,
        'ip' => '10.0.0.1',
        'port' => 51413,
        'uploaded' => $up,
        'downloaded' => $down,
        'left' => 0,
        'upload_delta' => $up,
        'download_delta' => $down,
        'seed_seconds' => $seed,
        'leech_seconds' => $leech,
    ]);

    $row->forceFill(['created_at' => $test->t->copy()->addMinutes($minutes)])->save();

    return $row;
}

function userTimes($test): array
{
    $row = DB::table($test->user->getTable())->where('id', $test->user->id)->first();

    return ['seed' => (int) $row->seedtime, 'leech' => (int) $row->leechtime];
}

function torrentTimes($test, ?int $torrentId = null): array
{
    $row = TorrentUser::query()
        ->where('user_id', $test->user->id)
        ->where('torrent_id', $torrentId ?? $test->torrent->id)
        ->firstOrFail();

    return ['seed' => $row->seedtime, 'leech' => $row->leechtime];
}

test('rows that credit only time reach the totals, on the user and torrent_user', function () {
    timedRow($this, 'started', 0);
    timedRow($this, 'regular', 30, seed: 1_800);
    timedRow($this, 'stopped', 60, seed: 1_800);

    $this->aggregator->run();

    expect(userTimes($this))->toBe(['seed' => 3_600, 'leech' => 0])
        ->and(torrentTimes($this))->toBe(['seed' => 3_600, 'leech' => 0]);
});

test('rows that credit only time add no bytes', function () {
    timedRow($this, 'started', 0);
    timedRow($this, 'regular', 30, up: 5_000, down: 1_000, seed: 1_800);
    timedRow($this, 'regular', 60, seed: 1_800);

    $this->aggregator->run();

    $torrentUser = TorrentUser::query()->where('user_id', $this->user->id)->firstOrFail();

    expect($this->user->fresh()->uploaded)->toBe(5_000)
        ->and($this->user->fresh()->downloaded)->toBe(1_000)
        ->and($torrentUser->uploaded)->toBe(5_000)
        ->and($torrentUser->downloaded)->toBe(1_000);
});

// A row that credits nothing (a first announce, a refusal) has nothing to add,
// so it must not leave a torrent_user row behind.
test('a row that credits neither bytes nor time writes no torrent_user row', function () {
    timedRow($this, 'started', 0);

    $this->aggregator->run();

    expect(TorrentUser::query()->count())->toBe(0)
        ->and(userTimes($this))->toBe(['seed' => 0, 'leech' => 0]);
});

test('folding in batches gives the same totals as folding at once', function () {
    $other = Torrent::create([
        'name' => 'Other', 'info_hash' => str_repeat('b', 40),
        'size' => 1_000, 'user_id' => $this->user->id,
    ]);

    timedRow($this, 'started', 0);
    timedRow($this, 'started', 1, torrentId: $other->id);
    timedRow($this, 'regular', 30, down: 600, leech: 1_800);
    timedRow($this, 'regular', 31, up: 50, seed: 1_800, torrentId: $other->id);
    timedRow($this, 'completed', 60, down: 400, leech: 1_800);
    timedRow($this, 'stopped', 61, seed: 1_800, torrentId: $other->id);
    timedRow($this, 'regular', 400, seed: 3_600);

    $this->aggregator->run(batchSize: 2);
    $batched = [userTimes($this), torrentTimes($this), torrentTimes($this, $other->id)];

    DB::table($this->user->getTable())->update(['seedtime' => 0, 'leechtime' => 0, 'uploaded' => 0, 'downloaded' => 0]);
    DB::table('torrent_user')->delete();
    DB::table('ledger_cursors')->where('stream', LedgerAggregator::STREAM)->delete();

    $this->aggregator->run(batchSize: 1_000);
    $atOnce = [userTimes($this), torrentTimes($this), torrentTimes($this, $other->id)];

    expect($batched)->toBe($atOnce)
        ->and($atOnce[1])->toBe(['seed' => 3_600, 'leech' => 3_600])
        ->and($atOnce[2])->toBe(['seed' => 3_600, 'leech' => 0]);
});

test('a second run over a caught-up ledger credits nothing more', function () {
    timedRow($this, 'started', 0);
    timedRow($this, 'regular', 30, seed: 1_800);

    $this->aggregator->run();
    $this->aggregator->run();

    expect(userTimes($this))->toBe(['seed' => 1_800, 'leech' => 0]);
});

test('leechtime is cast to an integer on the models that carry it', function () {
    timedRow($this, 'started', 0);
    timedRow($this, 'regular', 30, leech: 1_800);

    $this->aggregator->run();

    expect(TorrentUser::first()->leechtime)->toBe(1_800)
        ->and($this->user->fresh()->leechtime)->toBe(1_800);
});
