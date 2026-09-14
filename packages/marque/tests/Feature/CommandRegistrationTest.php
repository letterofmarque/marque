<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Marque\Marque\Console\InstallCommand;
use Marque\Marque\Tests\TestCase;

/**
 * The whole point of this package is that `composer require marque/marque`
 * leaves you with something to run. If the command is not registered, the
 * package has no reason to exist.
 */
final class CommandRegistrationTest extends TestCase
{
    public function test_it_registers_the_install_command(): void
    {
        $commands = $this->app[Kernel::class]->all();

        $this->assertArrayHasKey('marque:install', $commands);
        $this->assertInstanceOf(InstallCommand::class, $commands['marque:install']);
    }

    public function test_the_install_command_describes_itself(): void
    {
        // `php artisan list` is where a stranger looks after a require that
        // appeared to do nothing, so the description is load-bearing.
        $command = $this->app[Kernel::class]->all()['marque:install'];

        $this->assertNotSame('', trim((string) $command->getDescription()));
    }
}
