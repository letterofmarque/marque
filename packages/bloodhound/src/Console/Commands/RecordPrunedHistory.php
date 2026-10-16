<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Services\PrunedHistory;

/**
 * Record credit that an earlier prune deleted without carrying (#11021).
 *
 * The upgrade migration does this on any install with retention configured.
 * This is for an install that pruned in the past and has since turned
 * retention off: its pruned history still reads as drift, and only the
 * operator knows it pruned. See PrunedHistory for what is and is not recorded.
 */
class RecordPrunedHistory extends Command
{
    protected $signature = 'bloodhound:record-pruned-history';

    protected $description = 'Record credit an earlier prune deleted without carrying forward, so reconcile and rebuild account for it';

    public function handle(PrunedHistory $history): int
    {
        $recorded = $history->record();

        $this->info(sprintf(
            'Recorded pruned history for %d per-torrent total(s) for %d user(s): %d bytes up, %d bytes down.',
            $recorded['rows'],
            $recorded['users'],
            $recorded['uploaded'],
            $recorded['downloaded'],
        ));

        if ($recorded['rows'] === 0) {
            $this->line('Nothing to record: every per-torrent total is explained by the ledger.');
        }

        return self::SUCCESS;
    }
}
