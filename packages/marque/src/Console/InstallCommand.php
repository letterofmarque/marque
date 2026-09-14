<?php

declare(strict_types=1);

namespace Marque\Marque\Console;

use Illuminate\Console\Command;

/**
 * The front door.
 *
 * Stages land one Checkpoint at a time (Build #105): environment checks, the
 * interview and composer require, the @source wiring, the User model edit, the
 * home page, migrations, and a self-verification pass. Until those arrive this
 * command says so rather than reporting a success it has not performed —
 * which is the precise failure this whole Build exists to correct.
 */
class InstallCommand extends Command
{
    protected $signature = 'marque:install';

    protected $description = 'Install and wire up Marque — interviews you, then configures the app';

    public function handle(): int
    {
        $this->components->error('marque:install is not finished yet.');

        $this->line('  Stages are landing one at a time; this command will refuse');
        $this->line('  to run until it can actually wire an app up.');

        return self::FAILURE;
    }
}
