<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables the host app owns, stood up for tests.
 *
 * skipper ships no users table — users belong to the host (via
 * trove.user_model). Trove's own migrations add columns to it, so it has to
 * exist before they run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('user');
            $table->string('status')->default('active');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Package migrations register before this fixture (providers call
        // loadMigrationsFrom in boot), so rollback reverses that order and
        // reaches `users` while tables referencing it still exist. SQLite does
        // not enforce foreign keys by default and never notices; MySQL and
        // PostgreSQL both refuse.
        //
        // skipper adds no tables of its own — it is routes, a component and
        // views — so the dependants here are trove's alone. If skipper ever
        // gains a table that references torrents or users, it goes ABOVE these
        // lines, deepest first. See docs/new-package.md; getting this wrong
        // passes every test file in isolation and fails the suite as a whole.
        Schema::dropIfExists('torrents');
        Schema::dropIfExists('users');
    }
};
