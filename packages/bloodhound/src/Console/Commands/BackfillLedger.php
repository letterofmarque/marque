<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Services\LedgerBackfill;
use RuntimeException;

/**
 * Credit 7.x history the seedtime, leechtime and stopped bytes 8.0 would have.
 *
 * Optional, once, after upgrading. Without it, time starts at the upgrade and
 * nothing is wrong; with it, members are credited what the ledger proves they
 * did before. Recorded as carry: no ledger row is changed.
 */
class BackfillLedger extends Command
{
    protected $signature = 'bloodhound:backfill-ledger';

    protected $description = 'Credit pre-8.0 ledger history its seedtime, leechtime and stopped bytes, as carry';

    public function handle(LedgerBackfill $backfill): int
    {
        try {
            $credit = $backfill->run();
        } catch (RuntimeException $notYet) {
            $this->error($notYet->getMessage());

            return self::FAILURE;
        }

        if ($credit === null) {
            $this->info('Nothing to backfill: there is no pre-8.0 history, or it has been backfilled already.');

            return self::SUCCESS;
        }

        $sum = fn (string $column) => array_sum(array_column($credit, $column));

        $this->info(sprintf(
            'Credited %d member-torrent total(s): %d s seedtime, %d s leechtime, %d bytes up and %d down from stops. Recorded as carry; the totals already include it.',
            count($credit), $sum('seedtime'), $sum('leechtime'), $sum('uploaded'), $sum('downloaded'),
        ));

        return self::SUCCESS;
    }
}
