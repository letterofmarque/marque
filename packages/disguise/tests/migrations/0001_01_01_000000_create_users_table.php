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
            $table->string('role')->default('user');
            $table->string('passkey', 32)->nullable()->unique();
            $table->bigInteger('uploaded')->default(0);
            $table->bigInteger('downloaded')->default(0);
            $table->bigInteger('seedtime')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
