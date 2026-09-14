<?php

declare(strict_types=1);

namespace Marque\Marque\Console;

use Illuminate\Console\Command;
use Marque\Marque\Install\EnvironmentCheck;
use Marque\Marque\Install\EnvironmentReport;

/**
 * The front door.
 *
 * Stages land one Checkpoint at a time (Build #105): environment checks, the
 * interview and composer require, the @source wiring, the User model edit, the
 * home page, migrations, and a self-verification pass. Until those arrive this
 * command stops after the gate rather than reporting a success it has not
 * performed — which is the precise failure this whole Build exists to correct.
 */
class InstallCommand extends Command
{
    protected $signature = 'marque:install';

    protected $description = 'Install and wire up Marque — interviews you, then configures the app';

    public function handle(EnvironmentCheck $check): int
    {
        $this->components->info('Checking the environment');

        // Every deployment that serves announces needs Redis, because
        // threepio's peer storage is Redis-backed and the core require set
        // includes threepio. Once the interview lands (CP3) a catalogue-only
        // install can pass false here.
        $report = $check->run(needsRedis: true);

        $this->render($report);

        if ($report->isFatal()) {
            $this->newLine();
            $this->components->error('Nothing has been changed. Fix the above and run marque:install again.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->warn('The rest of marque:install is not finished yet.');
        $this->line('  The environment gate above is live; the remaining stages are landing');
        $this->line('  one at a time and this command will not claim to have wired anything.');

        return self::FAILURE;
    }

    private function render(EnvironmentReport $report): void
    {
        foreach (['database', 'redis', 'mail'] as $item) {
            if (! $report->checked($item)) {
                continue;
            }

            $this->components->twoColumnDetail(
                '  '.$item,
                $report->passed($item) ? '<fg=green>ok</>' : '<fg=red>needs attention</>',
            );
        }

        foreach ($report->failures() as $failure) {
            $this->newLine();
            $this->components->error($failure);
        }

        foreach ($report->warnings() as $warning) {
            $this->newLine();
            $this->components->warn($warning);
        }
    }
}
