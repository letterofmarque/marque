<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Services\LedgerAuditor;

/**
 * Check the ledger's own rows for internal contradictions.
 *
 * Reconciliation asks whether the totals match the ledger. This asks whether
 * the ledger itself is coherent — a different question, and one that only
 * became answerable once every row carried the baseline its delta was computed
 * against.
 *
 * A chain break is the useful finding: each says what went wrong between two
 * announces of one peer (a baseline lost, an interval credited twice). With
 * a persistent Redis it should not happen; when it does, the operator learns
 * which row, instead of never knowing.
 *
 * See Spec #99.
 */
class AuditLedger extends Command
{
    protected $signature = 'bloodhound:audit-ledger {--limit=20 : Rows to list per category}';

    protected $description = 'Verify the announce ledger is internally consistent';

    public function handle(LedgerAuditor $auditor): int
    {
        $audit = $auditor->audit();
        $limit = max(1, (int) $this->option('limit'));

        if (! $audit->hasBreaks()) {
            $this->info('Ledger is internally consistent.');

            return self::SUCCESS;
        }

        if ($audit->arithmeticBreaks !== []) {
            $count = count($audit->arithmeticBreaks);
            $this->error("{$count} row(s) whose credit does not match their own reported, prior and timing values:");

            foreach (array_slice($audit->arithmeticBreaks, 0, $limit) as $break) {
                $this->line("  row {$break['id']}: {$break['reason']}");
            }
        }

        if ($audit->chainBreaks !== []) {
            $count = count($audit->chainBreaks);
            $this->newLine();
            $this->error("{$count} broken baseline chain(s):");

            foreach (array_slice($audit->chainBreaks, 0, $limit) as $break) {
                $prior = $break['prior_up'] ?? 'null';
                $this->line("  row {$break['id']}: {$break['reason']} (diffed against {$prior}, previous announce reported {$break['previous_uploaded']})");
            }
        }

        return self::FAILURE;
    }
}
