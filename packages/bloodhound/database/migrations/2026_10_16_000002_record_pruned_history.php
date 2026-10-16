<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Marque\Bloodhound\Services\PrunedHistory;

// Job #187 (#11021): an install that pruned before pruning carried anything
// forward has credit no ledger row explains. Reconcile reports it as drift
// every day, and the rebuild it recommends would erase it. This records it as
// carry, once. See PrunedHistory for exactly what is and is not recorded.
//
// Only where retention is configured. An install that never pruned has no
// pruned history, so any gap it has is real drift, and recording it would
// hide it. An install that pruned once and has since turned retention off
// runs `php artisan bloodhound:record-pruned-history` (upgrading notes).
return new class extends Migration
{
    public function up(): void
    {
        $days = config('bloodhound.announce_log.retention_days');

        if ($days === null || $days === '' || (int) $days < 1) {
            return;
        }

        $recorded = app(PrunedHistory::class)->record();

        if ($recorded['rows'] === 0) {
            return;
        }

        $message = sprintf(
            'bloodhound: recorded pruned history for %d per-torrent total(s) for %d user(s) (%d bytes up, %d bytes down) so reconcile and rebuild account for credit an earlier prune deleted.',
            $recorded['rows'],
            $recorded['users'],
            $recorded['uploaded'],
            $recorded['downloaded'],
        );

        Log::info($message);

        if (app()->runningInConsole()) {
            fwrite(STDOUT, "  {$message}\n");
        }
    }

    public function down(): void
    {
        // Nothing to undo safely: carry recorded here is indistinguishable
        // from carry a later prune added on top of it.
    }
};
