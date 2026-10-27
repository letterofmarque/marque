<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * For the fail-open test: make SQLite reject what the real engines reject.
 *
 * PostgreSQL, MySQL and MariaDB refuse a subject_type longer than its column
 * (191). SQLite ignores string lengths, so without this its run of that test
 * would store the row and prove nothing. The trigger refuses the same row with
 * a real failed statement, not a PHP exception. Created here, before any test
 * transaction, and after staff_actions exists (this file sorts after it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bosun_test_reject_long_subject_type
            BEFORE INSERT ON staff_actions
            WHEN length(NEW.subject_type) > 191
            BEGIN
                SELECT RAISE(ABORT, 'subject_type too long');
            END
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS bosun_test_reject_long_subject_type');
        }
    }
};
