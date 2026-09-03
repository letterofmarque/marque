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
 * A chain break is the useful finding: it means a peer's baseline went missing
 * between two announces, which is what a Redis loss looks like after the fact.
 * Those bytes were never credited and cannot be recovered, but the operator at
 * least learns it happened instead of never knowing.
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
            $this->error("{$count} row(s) where the delta does not match reported minus prior:");

            foreach (array_slice($audit->arithmeticBreaks, 0, $limit) as $break) {
                $this->line("  row {$break['id']}: upload_delta {$break['upload_delta']}, expected {$break['expected_upload_delta']}");
            }
        }

        if ($audit->chainBreaks !== []) {
            $count = count($audit->chainBreaks);
            $this->newLine();
            $this->error("{$count} broken baseline chain(s) — a peer's baseline went missing:");

            foreach (array_slice($audit->chainBreaks, 0, $limit) as $break) {
                $prior = $break['prior_up'] ?? 'null';
                $this->line("  row {$break['id']}: diffed against {$prior}, previous announce reported {$break['previous_uploaded']}");
            }

            $this->newLine();
            $this->line('A chain break usually means Redis lost peer state. The bytes across the gap were never credited and cannot be recovered.');
        }

        return self::FAILURE;
    }
}
