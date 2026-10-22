<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// bloodhound 9.1.0 (Job #256): a member's seeding obligation in trouble under
// the hit-and-run rule. One row per (member, torrent), and only once they
// have been pre-warned: an obligation being met, or not yet overdue, has no
// row. A row that was struck stays, cleared or lapsed, so a torrent is never
// struck twice. No foreign keys, like torrent_user: the user model is the
// host app's.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hit_and_run_strikes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('torrent_id');
            $table->timestamp('prewarned_at')->nullable();
            $table->timestamp('struck_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'torrent_id']);
            $table->index(['user_id', 'struck_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hit_and_run_strikes');
    }
};
