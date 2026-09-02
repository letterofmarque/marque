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
        expect(DB::select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(1);
    });

    it('actually rejects an orphan row', function () {
        expect(fn () => DB::table('parley_posts')->insert([
            'thread_id' => 99999,
            'user_id' => 99999,
            'body' => 'orphan',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
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
