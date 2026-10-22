<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Services\RatioEnforcer;

/**
 * Apply the ratio rule: start watches, restrict when they run out, restore
 * members who recovered (#10732). Hourly on the scheduler.
 */
class EnforceRatio extends Command
{
    protected $signature = 'bloodhound:enforce-ratio {--chunk=1000 : Members to load per batch}';

    protected $description = 'Put members below min_ratio on watch, restrict them when it runs out, and restore those who recover';

    public function handle(RatioEnforcer $enforcer): int
    {
        $counts = $enforcer->run(max(1, (int) $this->option('chunk')));

        if (! $enforcer->enforcing()) {
            $this->info(sprintf(
                'ratio_mode is %s, so no minimum ratio is enforced; %d member(s) restored.',
                config('bloodhound.ratio_mode'),
                $counts['restored'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Ratio rule applied: %d put on watch, %d restricted, %d restored.',
            $counts['watched'],
            $counts['restricted'],
            $counts['restored'],
        ));

        return self::SUCCESS;
    }
}
