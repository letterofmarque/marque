<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * The schema is DB-agnostic by rule (docs/why.md): no raw SQL, no
 * database-specific types. These run on sqlite, which is the strictest of the
 * supported set for foreign keys and the one most likely to catch a mistake.
 */
describe('the test environment enforces referential integrity', function () {
    it('has sqlite foreign keys switched on', function () {
        // SQLite defaults to OFF, which would make every cascadeOnDelete and
        // nullOnDelete in the schema silently untested. If this ever goes back
        // to 0, the referential assertions elsewhere stop meaning anything.
        //
        // MySQL and Postgres enforce foreign keys unconditionally, and PRAGMA
        // is not valid syntax there — so this asserts nothing on them and is
        // skipped rather than failing with a syntax error that would read like
        // a broken package.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            test()->markTestSkipped('PRAGMA is SQLite-only; other engines enforce foreign keys unconditionally.');
        }

        expect(DB::select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(1);
    });

    it('actually rejects an orphan row', function () {
        // Inside a savepoint: Postgres aborts the whole surrounding
        // transaction on a constraint violation, so without one the deliberate
        // failure poisons every statement after it — including the test
        // harness's own bookkeeping. The rollback confines the damage to the
        // statement that was supposed to fail.
        DB::beginTransaction();

        try {
            expect(fn () => DB::table('parley_posts')->insert([
                'thread_id' => 99999,
                'user_id' => 99999,
                'body' => 'orphan',
                'created_at' => now(),
                'updated_at' => now(),
            ]))->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    });
});

describe('migrations', function () {
    it('creates every table', function (string $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    })->with(['parley_categories', 'parley_threads', 'parley_posts']);

    it('prefixes tables so they cannot collide with a host app', function () {
        // A host is entirely likely to have its own "posts" or "categories".
        expect(Schema::hasTable('posts'))->toBeFalse()
            ->and(Schema::hasTable('threads'))->toBeFalse()
            ->and(Schema::hasTable('categories'))->toBeFalse();
    });

    it('gives threads a nullable morph, so discussion attaches to anything', function () {
        expect(Schema::hasColumns('parley_threads', ['threadable_type', 'threadable_id']))->toBeTrue();
    });

    it('gives posts a body_format column', function () {
        // The schema-critical one: adding it after a site has data means a
        // migration plus a backfill.
        expect(Schema::hasColumn('parley_posts', 'body_format'))->toBeTrue();
    });

    it('gives posts a self-referencing reply column', function () {
        expect(Schema::hasColumn('parley_posts', 'reply_to_id'))->toBeTrue();
    });

    it('soft-deletes threads and posts', function (string $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue();
    })->with(['parley_threads', 'parley_posts']);
});
