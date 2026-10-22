<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Services\HitAndRunEnforcer;

/**
 * Apply the hit-and-run rule: pre-warn, strike, clear (Job #256). Hourly on
 * the scheduler.
 */
class EnforceHitAndRun extends Command
{
    protected $signature = 'bloodhound:enforce-hit-and-run {--chunk=1000 : Obligations to load per batch}';

    protected $description = 'Pre-warn and strike members away from torrents they owe seeding on, and clear strikes once seeded';

    public function handle(HitAndRunEnforcer $enforcer): int
    {
        if (! $enforcer->applies()) {
            $this->info('Hit-and-run is off (hit_and_run.enabled, or ratio_mode seedtime): nothing to do.');
            $enforcer->run();

            return self::SUCCESS;
        }

        $counts = $enforcer->run(max(1, (int) $this->option('chunk')));

        $this->info(sprintf(
            'Hit-and-run rule applied: %d pre-warned, %d struck, %d cleared.',
            $counts['prewarned'],
            $counts['struck'],
            $counts['cleared'],
        ));

        return self::SUCCESS;
    }
}
