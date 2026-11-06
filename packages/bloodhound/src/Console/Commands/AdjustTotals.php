<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Marque\Bloodhound\Console\Commands\Concerns\RecordsLedgerAdjustments;
use Marque\Bloodhound\Services\LedgerAdjuster;

/**
 * Give or take a stated amount, on the record (Job #213).
 *
 * For credit an operator decides a member is owed, or isn't: a speed forfeit
 * that was a false positive (that credit never reached the totals), figures
 * double-counted in an import. The adjustment and the member's totals move
 * together, so reconcile stays clean and a rebuild keeps it.
 */
class AdjustTotals extends Command
{
    use RecordsLedgerAdjustments;

    protected $signature = 'bloodhound:adjust-totals
        {user : The member\'s user id}
        {--torrent=0 : The torrent it is for; 0 (the default) is the member as a whole}
        {--uploaded=0 : Bytes, signed}
        {--downloaded=0 : Bytes, signed}
        {--seedtime=0 : Seconds, signed}
        {--leechtime=0 : Seconds, signed}
        {--reason= : Why (required, kept with the adjustment)}
        {--by= : The deciding admin\'s user id (required)}
        {--force : Write without asking}';

    protected $description = 'Credit or debit a member\'s totals by a stated amount, with who and why';

    public function handle(LedgerAdjuster $adjuster): int
    {
        $amounts = [];

        foreach (LedgerAdjuster::TOTALS as $column) {
            $value = (string) $this->option($column);

            if (preg_match('/^-?\d+$/', $value) !== 1) {
                $this->error("--{$column} must be a whole number (bytes or seconds, signed): got \"{$value}\".");

                return self::FAILURE;
            }

            if ((int) $value !== 0) {
                $amounts[$column] = (int) $value;
            }
        }

        if (! ctype_digit((string) $this->option('torrent'))) {
            $this->error('--torrent must be a torrent id, or 0 for the member as a whole.');

            return self::FAILURE;
        }

        $reason = $this->reason();
        $by = $reason === null ? null : $this->by();

        if ($by === null) {
            return self::FAILURE;
        }

        $userId = (int) $this->argument('user');
        $torrentId = (int) $this->option('torrent');

        $userModel = config('trove.user_model', 'App\\Models\\User');
        $current = DB::table((new $userModel)->getTable())->where('id', $userId)->first(LedgerAdjuster::TOTALS);

        if ($current !== null) {
            $this->line("Member {$userId}, ".($torrentId === 0 ? 'as a whole' : "torrent {$torrentId}").", by user {$by}: {$reason}");

            foreach ($amounts as $column => $amount) {
                $this->line(sprintf('  %s: %d → %d (%+d)', $column, (int) $current->{$column}, (int) $current->{$column} + $amount, $amount));
            }
        }

        if (! $this->agreed('Make this adjustment?')) {
            return self::FAILURE;
        }

        try {
            $adjuster->adjust($userId, $torrentId, $amounts, $by, $reason);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Log::warning("bloodhound ledger adjustment — user {$userId} on torrent {$torrentId}: ".json_encode($amounts)." by user {$by}: {$reason}");
        $this->info('Adjusted.');

        return self::SUCCESS;
    }
}
