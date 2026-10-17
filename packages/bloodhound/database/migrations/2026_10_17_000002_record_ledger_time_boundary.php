<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// bloodhound 8.0.0 (Job #193): marks where rows that carry their own time
// begin. Rows at or below this id were written by 7.x: no seconds, no
// prior_at, a stopped row with null priors and zero bytes, backwards counters
// clamped to 0. The audit checks them by the rules they were written under,
// rather than reporting a break for every 7.x stop.
//
// A cursor row because ledger_cursors already holds positions in the ledger,
// and it is written once: a fresh install records 0, so every row is checked
// by the 8.0 rules.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('ledger_cursors')->where('stream', 'time_columns_from')->exists()) {
            return;
        }

        $last = (int) DB::connection(config('bloodhound.announce_log.connection'))->table('announce_log')->max('id');

        DB::table('ledger_cursors')->insert([
            'stream' => 'time_columns_from',
            'position' => $last,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ledger_cursors')->where('stream', 'time_columns_from')->delete();
    }
};
