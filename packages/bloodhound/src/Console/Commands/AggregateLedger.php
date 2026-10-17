<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\RedisDurability;

/**
 * Fold pending ledger rows into user and per-torrent totals.
 *
 * Scheduled, and that is the point: the queue is no longer a data path, only a
 * scheduling convenience. If every queued job were lost, this tick still
 * brings the totals to where the ledger says they should be — aggregation runs
 * late rather than wrong.
 *
 * Folds up to the horizon (the highest id the previous run saw), so a row
 * whose insert was still committing is never stepped over: totals trail the
 * ledger by a run or two.
 *
 * See Spec #99.
 */
class AggregateLedger extends Command
{
    protected $signature = 'bloodhound:aggregate-ledger {--batch=1000 : Ledger rows per transaction}';

    protected $description = 'Fold pending announce ledger rows into user and per-torrent totals';

    public function handle(LedgerAggregator $aggregator, RedisDurability $durability): int
    {
        // Every minute, whether or not anyone announces: a tracker nobody is
        // using still learns its Redis cannot be trusted.
        $durability->reportFromSchedule();

        $folded = $aggregator->runScheduled(batchSize: max(1, (int) $this->option('batch')));

        $this->info($folded === 0
            ? 'Nothing to fold — totals are up to date with the ledger.'
            : "Folded {$folded} ledger row(s) into user and per-torrent totals.");

        return self::SUCCESS;
    }
}
