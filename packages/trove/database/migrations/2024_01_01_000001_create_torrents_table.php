<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('torrents')) {
            return;
        }

        Schema::create('torrents', function (Blueprint $table) {
            $table->id();
            $table->string('info_hash', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('file_count')->default(1);
            $table->string('torrent_file')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index('name');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('torrents');
    }
};
