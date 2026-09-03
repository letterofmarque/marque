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
            $table->string('password')->nullable();

            // Laravel's own users table has this, and package migrations
            // legitimately position columns relative to it. SQLite ignores
            // ->after() entirely so its absence went unnoticed; MySQL rejects
            // the ALTER outright.
            $table->rememberToken();
            $table->string('role')->default('user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
