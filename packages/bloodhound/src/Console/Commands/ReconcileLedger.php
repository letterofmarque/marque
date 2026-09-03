<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Marque\Bloodhound\Services\LedgerAuditor;

/**
 * Compare the totals the site reads against the ledger they come from.
 *
 * Scheduled, and loud when it finds something. A silent drift in a ratio is
 * the failure this whole Spec exists to prevent, so this reports rather than
 * repairs — an operator should know a number went wrong before anything
 * quietly changes it back. `bloodhound:rebuild-totals` is the fix.
 *
 * See Spec #99.
 */
class ReconcileLedger extends Command
{
    protected $signature = 'bloodhound:reconcile-ledger';

    protected $description = 'Check user and per-torrent totals against the announce ledger';

    public function handle(LedgerAuditor $auditor): int
    {
        $report = $auditor->reconcile();

        if ($report->pending > 0) {
            $this->line("{$report->pending} ledger row(s) not yet aggregated (backlog, not drift).");
        }

        if (! $report->hasDrift()) {
            $this->info('Totals agree with the ledger.');

            return self::SUCCESS;
        }

        $this->error('Totals DISAGREE with the ledger.');

        foreach ($report->userDrift as $drift) {
            $line = "user {$drift['user_id']}: {$drift['column']} recorded {$drift['recorded']}, ledger says {$drift['expected']}";
            $this->line("  {$line}");
            Log::warning("bloodhound ledger drift — {$line}");
        }

        foreach ($report->torrentUserDrift as $drift) {
            $line = "user {$drift['user_id']} on torrent {$drift['torrent_id']}: {$drift['column']} recorded {$drift['recorded']}, ledger says {$drift['expected']}";
            $this->line("  {$line}");
            Log::warning("bloodhound ledger drift — {$line}");
        }

        $this->newLine();
        $this->line('Run bloodhound:rebuild-totals to recompute from the ledger.');

        return self::FAILURE;
    }
}
