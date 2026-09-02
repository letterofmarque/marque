<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Found while implementing Spec #98 (bloodhound announce_log): both
// bloodhound and hound's AnnounceService call $torrent->increment('times_completed')
// on every 'completed' announce, and bloodhound's handleRegular() reads/writes
// $torrent->visible — but neither column has ever existed on the torrents
// table. The increment() call errors at the SQL layer (a genuine 500 on
// every real BitTorrent client finishing a download); the visible check
// silently read null via Eloquent's missing-attribute behaviour, which is
// why nothing surfaced this reading-side until a real completed-event test
// exercised the write.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('torrents', function (Blueprint $table) {
            if (! Schema::hasColumn('torrents', 'times_completed')) {
                $table->unsignedInteger('times_completed')->default(0)->after('file_count');
            }

            if (! Schema::hasColumn('torrents', 'visible')) {
                // Existing torrents default true — hiding torrents retroactively
                // on upgrade would be a surprise behaviour change, not a bug fix.
                // New torrents flow through the same default until a real seeder
                // announce flips it, per bloodhound's existing logic.
                $table->boolean('visible')->default(true)->after('times_completed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('torrents', function (Blueprint $table) {
            $table->dropColumn(['times_completed', 'visible']);
        });
    }
};
