<?php

declare(strict_types=1);

// Job #193 (design 5.1, F11 and F13), on the real engines: while one worker
// holds the cursor row, a second fold and a rebuild wait for it rather than
// working from a position that is about to change.
//
// A second connection holds the lock in an open transaction; the worker under
// test gives up after a second, which is the proof it was waiting. SQLite has
// no row locks (a writer takes the whole database), so it is not this test's
// subject; the compare-and-swap covers it.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\LedgerRebuilder;

beforeEach(function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('Row locks are a MySQL, MariaDB and PostgreSQL matter.');
    }

    config(['database.connections.holder' => config('database.connections.testing')]);

    DB::table('users')->insert(['id' => 1, 'name' => 'L', 'email' => 'lock@example.com', 'password' => 'p']);
    AnnounceLog::create([
        'user_id' => 1, 'torrent_id' => 1, 'peer_id' => '-qB4210-aaaaaaaaaaaa', 'event' => 'regular',
        'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => 100, 'downloaded' => 0, 'left' => 0,
        'upload_delta' => 100, 'download_delta' => 0,
    ]);
    app(LedgerAggregator::class)->run();

    // Short waits for the worker under test, so a wait shows as a timeout.
    DB::getDriverName() === 'pgsql'
        ? DB::statement("SET lock_timeout = '1s'")
        : DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    $this->holder = DB::connection('holder');
    $this->holder->beginTransaction();
    $this->holder->table('ledger_cursors')->where('stream', LedgerAggregator::STREAM)->update(['position' => DB::raw('position')]);
});

afterEach(function () {
    if (isset($this->holder)) {
        $this->holder->rollBack();
        DB::purge('holder');
    }
});

test('a fold waits for the cursor another worker holds', function () {
    AnnounceLog::create([
        'user_id' => 1, 'torrent_id' => 1, 'peer_id' => '-qB4210-aaaaaaaaaaaa', 'event' => 'regular',
        'ip' => '10.0.0.1', 'port' => 51413, 'uploaded' => 200, 'downloaded' => 0, 'left' => 0,
        'upload_delta' => 100, 'download_delta' => 0,
    ]);

    expect(fn () => app(LedgerAggregator::class)->run())->toThrow(QueryException::class);
    expect((int) DB::table('users')->where('id', 1)->value('uploaded'))->toBe(100);
});

test('a rebuild waits for the cursor a fold holds', function () {
    expect(fn () => app(LedgerRebuilder::class)->rebuild())->toThrow(QueryException::class);
    expect((int) DB::table('users')->where('id', 1)->value('uploaded'))->toBe(100);
});
