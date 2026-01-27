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
            $table->string('passkey', 32)->nullable()->unique();
            $table->unsignedBigInteger('uploaded')->default(0);
            $table->unsignedBigInteger('downloaded')->default(0);
            $table->unsignedBigInteger('seedtime')->default(0);
            $table->boolean('enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
