<?php

declare(strict_types=1);

// Job #10548. SQLite ships with foreign keys OFF, which silently makes every
// cascadeOnDelete() and constrained() in the schema untested — assertions
// about delete behaviour pass whether or not the constraint exists.
//
// That went unnoticed for the life of the project. Running the suite against
// MySQL and PostgreSQL for the first time (2026-09-03) found seven test-only
// bugs SQLite had been hiding, including orphan rows the FK was there to
// prevent.
//
// This guard exists so the cheap engine cannot quietly go back to proving the
// least.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('the test database enforces foreign keys', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        // MySQL and Postgres enforce unconditionally and have no such pragma.
        expect(true)->toBeTrue();

        return;
    }

    expect(DB::select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(1);
});

test('an orphan row is actually rejected', function () {
    // Wrapped: Postgres aborts the surrounding transaction on a constraint
    // violation, so the deliberate failure would poison everything after it.
    DB::beginTransaction();

    try {
        expect(fn () => DB::table('torrents')->insert([
            'info_hash' => str_repeat('f', 40),
            'name' => 'Orphan',
            'size' => 1,
            'user_id' => 999999,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    } finally {
        DB::rollBack();
    }
});
