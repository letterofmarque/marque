<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('torrent_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45); // IPv6 support
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('completed_at');

            // Prevent duplicate snatches
            $table->unique(['user_id', 'torrent_id']);

            // Index for lookups
            $table->index(['torrent_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snatches');
    }
};
