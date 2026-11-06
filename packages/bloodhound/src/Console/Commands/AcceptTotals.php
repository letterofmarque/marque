<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Marque\Bloodhound\Console\Commands\Concerns\RecordsLedgerAdjustments;
use Marque\Bloodhound\Services\LedgerAdjuster;
use Marque\Bloodhound\Services\LedgerAuditor;

/**
 * Record a member's stored totals as correct where the ledger can't explain
 * them (Job #213).
 *
 * For totals that are real and that no ledger row accounts for: figures
 * imported from an earlier tracker, a 4.1.0 install's unexplained bytes
 * (#11051). Without this, reconcile reports them as drift forever and the
 * rebuild it recommends erases them. The gap is recorded as ledger
 * adjustments, with who decided and why; the totals themselves don't move.
 *
 * The opposite of bloodhound:rebuild-totals, which says the ledger is right
 * and the totals are wrong. Reconcile can't tell which; the operator can.
 */
class AcceptTotals extends Command
{
    use RecordsLedgerAdjustments;

    protected $signature = 'bloodhound:accept-totals
        {--user= : The member whose totals are right}
        {--all : Every member reconcile reports as drifting}
        {--reason= : Why the stored totals are right (required, kept with the adjustment)}
        {--by= : The deciding admin\'s user id (required)}
        {--force : Write without asking}';

    protected $description = 'Record stored totals the ledger cannot explain as correct, with who and why';

    /**
     * Rows shown before asking; the rest are counted.
     */
    private const SHOWN = 50;

    public function handle(LedgerAdjuster $adjuster, LedgerAuditor $auditor): int
    {
        if (($this->option('user') === null) === ! $this->option('all')) {
            $this->error('Name one member with --user=<id>, or every drifting member with --all.');

            return self::FAILURE;
        }

        $reason = $this->reason();
        $by = $reason === null ? null : $this->by();

        if ($by === null) {
            return self::FAILURE;
        }

        $users = $this->option('all') ? $this->drifting($auditor) : [(int) $this->option('user')];

        $gaps = [];
        $refused = [];

        foreach ($users as $userId) {
            try {
                $preview = $adjuster->preview($userId);
            } catch (InvalidArgumentException $e) {
                // One named member who is nobody is the operator's mistake.
                // Under --all it is a deleted member's leftover torrent_user
                // rows (no foreign key holds them), skipped like any refusal.
                if (! $this->option('all')) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                $refused[$userId] = $e->getMessage();

                continue;
            }

            if ($preview['refusal'] !== null) {
                $refused[$userId] = $preview['refusal'];
            }

            foreach ($preview['gaps'] as $gap) {
                $gaps[] = $gap;
            }
        }

        // A member accept would refuse is shown and skipped, never written
        // halfway through a run (found by the Job #213 check).
        foreach ($refused as $userId => $why) {
            $this->warn("Skipping member {$userId}: {$why}");
        }

        if (! $this->option('all') && $refused !== []) {
            return self::FAILURE;
        }

        if ($gaps === []) {
            if ($refused !== []) {
                $this->line('Nothing else to accept.');

                return self::FAILURE;
            }

            $this->info('The ledger already explains these totals: nothing to accept.');

            return self::SUCCESS;
        }

        $members = array_values(array_unique(array_column($gaps, 'user_id')));

        $this->line('These go on the record as adjustments (torrent 0 is the member as a whole). The totals themselves do not change.');
        $this->table(
            ['member', 'torrent', 'uploaded', 'downloaded', 'seedtime', 'leechtime'],
            array_map(fn (array $gap) => [$gap['user_id'], $gap['torrent_id'], $gap['uploaded'], $gap['downloaded'], $gap['seedtime'], $gap['leechtime']], array_slice($gaps, 0, self::SHOWN)),
        );

        if (count($gaps) > self::SHOWN) {
            $this->line('…and '.(count($gaps) - self::SHOWN).' more.');
        }

        $this->line(count($gaps).' adjustment(s) for '.count($members)." member(s), by user {$by}: {$reason}");

        if (! $this->agreed('Record these as correct?')) {
            return self::FAILURE;
        }

        $written = 0;
        $accepted = 0;

        foreach ($members as $userId) {
            // Measured again under the lock: a fold or a rebuild between the
            // preview and here can change the answer for one member, and that
            // member alone is skipped.
            try {
                $rows = $adjuster->accept($userId, $by, $reason);
            } catch (InvalidArgumentException $e) {
                $this->warn("Skipping member {$userId}: {$e->getMessage()}");

                continue;
            }

            $written += count($rows);
            $accepted += $rows === [] ? 0 : 1;

            if ($rows !== []) {
                Log::warning("bloodhound ledger adjustment — user {$userId}: stored totals accepted as correct (".count($rows)." row(s)) by user {$by}: {$reason}");
            }
        }

        $this->info("Recorded {$written} adjustment(s) for {$accepted} member(s).");

        return self::SUCCESS;
    }

    /**
     * Every member reconcile reports, on their own totals or a torrent's.
     *
     * @return list<int>
     */
    private function drifting(LedgerAuditor $auditor): array
    {
        $report = $auditor->reconcile();

        $ids = array_merge(array_column($report->userDrift, 'user_id'), array_column($report->torrentUserDrift, 'user_id'));
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
