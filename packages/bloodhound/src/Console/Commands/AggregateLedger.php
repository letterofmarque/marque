<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Services\LedgerAggregator;

/**
 * Fold pending ledger rows into user and per-torrent totals.
 *
 * Scheduled, and that is the point: the queue is no longer a data path, only a
 * scheduling convenience. If every queued job were lost, this tick still
 * brings the totals to where the ledger says they should be — aggregation runs
 * late rather than wrong.
 *
 * See Spec #99.
 */
class AggregateLedger extends Command
{
    protected $signature = 'bloodhound:aggregate-ledger {--batch=1000 : Ledger rows per transaction}';

    protected $description = 'Fold pending announce ledger rows into user and per-torrent totals';

    public function handle(LedgerAggregator $aggregator): int
    {
        $folded = $aggregator->run(batchSize: max(1, (int) $this->option('batch')));

        $this->info($folded === 0
            ? 'Nothing to fold — totals are up to date with the ledger.'
            : "Folded {$folded} ledger row(s) into user and per-torrent totals.");

        return self::SUCCESS;
    }
}
