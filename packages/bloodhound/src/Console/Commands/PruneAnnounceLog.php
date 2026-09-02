<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Marque\Bloodhound\Models\AnnounceLog;

/**
 * Enforces config('bloodhound.announce_log.retention_days') (Spec #98).
 *
 * Does nothing when retention_days is null, which is the default: once an
 * operator has opted into logging at all, how long to keep it is their call.
 * The README warns plainly that this means unbounded growth on a busy tracker
 * — that's a stated tradeoff, not an oversight, so this command says why it
 * did nothing rather than exiting silently and looking broken.
 */
class PruneAnnounceLog extends Command
{
    protected $signature = 'bloodhound:prune-announce-log';

    protected $description = 'Delete announce_log rows older than the configured retention window';

    public function handle(): int
    {
        $days = config('bloodhound.announce_log.retention_days');

        // env() hands this back as a string, so normalise before comparing.
        // A configured 0 would mean "delete everything immediately", which is
        // almost certainly a misconfiguration rather than an intent — treat it
        // the same as unset rather than silently wiping the table.
        if ($days === null || $days === '' || (int) $days < 1) {
            $this->info('No retention configured (announce_log.retention_days is not set) — keeping everything.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays((int) $days);

        $deleted = AnnounceLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} announce log row(s) older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
