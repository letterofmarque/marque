<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// bloodhound 9.1.0 (Job #256): when a member was last seen on a torrent, as
// unix seconds: the newest folded ledger row's stamp, kept by the aggregator
// and never moved backwards. Hit-and-run measures "not seeding" from it.
// updated_at won't do, because a rebuild moves it.
//
// Seconds, not a timestamp, so the fold can keep the larger of two values in
// the same batched statement it already writes, the same on all four engines.
//
// Not backfilled: that would be one UPDATE per row on a large tracker. A row
// with no value is read through updated_at until its next fold sets it.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('torrent_user', 'last_seen')) {
            return;
        }

        Schema::table('torrent_user', function (Blueprint $table) {
            $table->unsignedBigInteger('last_seen')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('torrent_user', 'last_seen')) {
            Schema::table('torrent_user', function (Blueprint $table) {
                $table->dropColumn('last_seen');
            });
        }
    }
};
