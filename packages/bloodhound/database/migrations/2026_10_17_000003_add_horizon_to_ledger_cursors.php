<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// bloodhound 8.0.0 (Job #193, F12): the scheduled fold stops at the highest
// announce_log id the previous run saw, so a row whose insert was still
// committing when a later id landed is never stepped over.
//
// Set to the head of the ledger here, so an upgraded install's first
// scheduled run carries on folding rather than waiting a tick for a horizon.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ledger_cursors', 'horizon')) {
            Schema::table('ledger_cursors', function (Blueprint $table) {
                $table->unsignedBigInteger('horizon')->default(0)->after('position');
            });
        }

        $head = (int) DB::connection(config('bloodhound.announce_log.connection'))->table('announce_log')->max('id');

        DB::table('ledger_cursors')->where('stream', 'user_totals')->update(['horizon' => $head]);
    }

    public function down(): void
    {
        Schema::table('ledger_cursors', function (Blueprint $table) {
            $table->dropColumn('horizon');
        });
    }
};
