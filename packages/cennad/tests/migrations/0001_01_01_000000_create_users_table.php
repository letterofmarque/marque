<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            // Laravel's own users table has this, and package migrations
            // legitimately position columns relative to it. SQLite ignores
            // ->after() entirely so its absence went unnoticed; MySQL rejects
            // the ALTER outright.
            $table->rememberToken();
            $table->string('role')->default('user');
            $table->string('announce_key', 32)->nullable()->unique();
            $table->bigInteger('uploaded')->default(0);
            $table->bigInteger('downloaded')->default(0);
            $table->bigInteger('seedtime')->default(0);
        });
    }

    public function down(): void
    {
        // Package migrations register before this fixture (providers call
        // loadMigrationsFrom in boot), so rollback reverses that order and
        // reaches `users` while tables referencing it still exist. SQLite does
        // not enforce foreign keys by default and never noticed; MySQL and
        // PostgreSQL both refuse.
        //
        // Postgres ignores disableForeignKeyConstraints for DROP TABLE, so the
        // portable fix is to take the dependants down first.
        Schema::dropIfExists('torrents');
        Schema::dropIfExists('users');
    }
};
