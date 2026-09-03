<?php

declare(strict_types=1);

// CP4 of Build #92 — the data-migration half.
//
// The carry-over runs once, against real installs holding real completion
// history. It is the part of this checkpoint that can destroy something, so it
// gets tested directly rather than assumed: the migration is re-run by hand
// here against a rebuilt `snatches` table, which is the only way to exercise
// code that normally runs before any test does.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Models\TorrentUser;

/**
 * Rebuild the pre-migration `snatches` table and seed it.
 *
 * @param  array<int, array{user_id: int, torrent_id: int, completed_at: string}>  $rows
 */
function seedLegacySnatches(array $rows): void
{
    Schema::dropIfExists('snatches');

    Schema::create('snatches', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('torrent_id');
        $table->string('ip', 45);
        $table->string('user_agent', 255)->nullable();
        $table->timestamp('completed_at');

        $table->unique(['user_id', 'torrent_id']);
    });

    // Real users: the migration's down() recreates snatches WITH a foreign
    // key, and MySQL/Postgres enforce it. SQLite never did, which is how rows
    // referencing users 1 and 2 went unnoticed.
    foreach (array_unique(array_column($rows, 'user_id')) as $userId) {
        if (! DB::table('users')->where('id', $userId)->exists()) {
            DB::table('users')->insert([
                'id' => $userId,
                'name' => "User {$userId}",
                'email' => "carryover{$userId}@example.com",
                'password' => 'password',
            ]);
        }
    }

    foreach (array_unique(array_column($rows, 'torrent_id')) as $torrentId) {
        if (! DB::table('torrents')->where('id', $torrentId)->exists()) {
            DB::table('torrents')->insert([
                'id' => $torrentId,
                'name' => "Torrent {$torrentId}",
                'info_hash' => str_pad((string) $torrentId, 40, '0', STR_PAD_LEFT),
                'size' => 1_000,
                'user_id' => array_values($rows)[0]['user_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    DB::table('snatches')->insert(array_map(fn (array $row) => [
        'user_id' => $row['user_id'],
        'torrent_id' => $row['torrent_id'],
        'ip' => '10.0.0.1',
        'user_agent' => 'qBittorrent/4.5.0',
        'completed_at' => $row['completed_at'],
    ], $rows));
}

/**
 * Re-run the migration that carries snatches over.
 */
function runCarryOver(): void
{
    DB::table('torrent_user')->delete();

    $migration = require __DIR__.'/../../database/migrations/2026_09_03_000002_create_torrent_user_table.php';

    // The table already exists from the suite's own migration run, so only the
    // carry-over and the drop are exercised here.
    (function () {
        $this->carryOverSnatches();
    })->call($migration);

    Schema::dropIfExists('snatches');
}

test('every snatch becomes a torrent_user row', function () {
    seedLegacySnatches([
        ['user_id' => 1, 'torrent_id' => 10, 'completed_at' => '2026-01-01 10:00:00'],
        ['user_id' => 1, 'torrent_id' => 11, 'completed_at' => '2026-02-01 10:00:00'],
        ['user_id' => 2, 'torrent_id' => 10, 'completed_at' => '2026-03-01 10:00:00'],
    ]);

    runCarryOver();

    expect(TorrentUser::count())->toBe(3);
});

// A snatch held one date. It is the user's first completion as far as anything
// knows, so it populates both — and times_completed is 1 because that is all
// the old table could ever represent, not because we know it happened once.
test('the single date populates first and last, with one completion', function () {
    seedLegacySnatches([
        ['user_id' => 1, 'torrent_id' => 10, 'completed_at' => '2026-01-01 10:00:00'],
    ]);

    runCarryOver();

    $row = TorrentUser::first();

    expect($row->first_completed_at->toDateTimeString())->toBe('2026-01-01 10:00:00')
        ->and($row->last_completed_at->toDateTimeString())->toBe('2026-01-01 10:00:00')
        ->and($row->times_completed)->toBe(1);
});

// Per-torrent byte history never existed, so it starts at zero. Inventing a
// number here would be worse than the gap: it would look like evidence.
test('byte counters start at zero, because that history was never kept', function () {
    seedLegacySnatches([
        ['user_id' => 1, 'torrent_id' => 10, 'completed_at' => '2026-01-01 10:00:00'],
    ]);

    runCarryOver();

    $row = TorrentUser::first();

    expect($row->uploaded)->toBe(0)
        ->and($row->downloaded)->toBe(0)
        ->and($row->seedtime)->toBe(0);
});

test('the carried date survives a later redownload', function () {
    seedLegacySnatches([
        ['user_id' => 1, 'torrent_id' => 10, 'completed_at' => '2026-01-01 10:00:00'],
    ]);

    runCarryOver();

    Carbon::setTestNow('2026-07-01 10:00:00');
    $row = TorrentUser::recordCompletion(1, 10);
    Carbon::setTestNow();

    expect($row->first_completed_at->toDateTimeString())->toBe('2026-01-01 10:00:00')
        ->and($row->last_completed_at->toDateTimeString())->toBe('2026-07-01 10:00:00')
        ->and($row->times_completed)->toBe(2);
});

test('an install with no snatches table migrates cleanly', function () {
    Schema::dropIfExists('snatches');

    runCarryOver();

    expect(TorrentUser::count())->toBe(0);
});

test('an empty snatches table carries nothing over', function () {
    seedLegacySnatches([]);

    runCarryOver();

    expect(TorrentUser::count())->toBe(0);
});

// Chunked at 500, so anything relying on a single pass would break here.
test('carries over more rows than one chunk', function () {
    $rows = [];

    for ($i = 1; $i <= 600; $i++) {
        $rows[] = ['user_id' => 1, 'torrent_id' => $i, 'completed_at' => '2026-01-01 10:00:00'];
    }

    seedLegacySnatches($rows);

    runCarryOver();

    expect(TorrentUser::count())->toBe(600);
});
