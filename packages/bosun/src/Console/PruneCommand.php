<?php

declare(strict_types=1);

namespace Marque\Bosun\Console;

use Illuminate\Console\Command;
use Marque\Bosun\Models\StaffActionEntry;

/**
 * Delete staff actions older than the retention period.
 *
 * Nothing is pruned by default: with bosun.retention_days unset the log is
 * kept for good. Set it, or pass --days, and schedule this to trim the log.
 */
class PruneCommand extends Command
{
    protected $signature = 'bosun:prune {--days= : Delete staff actions older than this many days (default: bosun.retention_days)}';

    protected $description = 'Delete staff actions older than the retention period';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('bosun.retention_days');

        if ($days === null || $days === '') {
            $this->info('Nothing to prune: staff actions are kept for good. Set bosun.retention_days (BOSUN_RETENTION_DAYS) or pass --days.');

            return self::SUCCESS;
        }

        if (! ctype_digit((string) $days) || (int) $days < 1) {
            $this->error("--days must be a whole number of days, 1 or more: got [{$days}].");

            return self::FAILURE;
        }

        $deleted = StaffActionEntry::query()
            ->where('created_at', '<', now()->subDays((int) $days))
            ->delete();

        $this->info("Deleted {$deleted} staff action(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
