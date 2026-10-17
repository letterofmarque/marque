<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Marque\Bloodhound\Services\TimeOpeningBalance;

// bloodhound 8.0.0 (Job #193, F10): stored seedtime and leechtime that no
// ledger row explains (bloodhound 7.x always wrote 0, so this is imported or
// hand-entered time) is recorded as carry. Without it, reconcile reports it
// as drift and the "rebuild once" upgrade step erases it. Idempotent: it
// records only a gap above what the ledger already explains.
return new class extends Migration
{
    public function up(): void
    {
        app(TimeOpeningBalance::class)->record();
    }

    public function down(): void
    {
        // Carry is credit; it is not taken back by a rollback.
    }
};
