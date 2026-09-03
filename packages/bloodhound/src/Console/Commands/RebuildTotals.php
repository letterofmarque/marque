<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Services\LedgerRebuilder;

/**
 * Recompute user and per-torrent totals from the ledger.
 *
 * Exists so that a disputed ratio is settled by replaying the record rather
 * than argued about. Written now, tested now, rather than improvised during
 * whatever incident first calls for it.
 *
 * See Spec #99.
 */
class RebuildTotals extends Command
{
    protected $signature = 'bloodhound:rebuild-totals {--user= : Rebuild one user instead of everyone}';

    protected $description = 'Recompute user and per-torrent totals from the announce ledger';

    public function handle(LedgerRebuilder $rebuilder): int
    {
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;

        $rebuilder->rebuild($userId);

        $this->info($userId === null
            ? 'Rebuilt all user and per-torrent totals from the ledger.'
            : "Rebuilt totals for user {$userId} from the ledger.");

        return self::SUCCESS;
    }
}
