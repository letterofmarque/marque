<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Models\LedgerAdjustment;

/**
 * The record of operators' corrections (Job #213): who, when, why and how
 * much, newest first. Readable without bosun's staff log.
 */
class ListLedgerAdjustments extends Command
{
    protected $signature = 'bloodhound:ledger-adjustments
        {--user= : One member\'s}
        {--limit=50 : How many, newest first}';

    protected $description = 'List the ledger adjustments operators have made, with who and why';

    public function handle(): int
    {
        $rows = LedgerAdjustment::query()
            ->when($this->option('user') !== null, fn ($q) => $q->where('user_id', (int) $this->option('user')))
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No ledger adjustments.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'when', 'member', 'torrent', 'uploaded', 'downloaded', 'seedtime', 'leechtime', 'by', 'reason'],
            $rows->map(fn (LedgerAdjustment $row) => [
                $row->id,
                $row->created_at?->toDateTimeString(),
                $row->user_id,
                $row->torrent_id === 0 ? '0 (member)' : $row->torrent_id,
                $row->uploaded,
                $row->downloaded,
                $row->seedtime,
                $row->leechtime,
                $row->made_by,
                $row->reason,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
