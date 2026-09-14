<?php

declare(strict_types=1);

namespace Marque\Marque;

use Illuminate\Support\ServiceProvider;
use Marque\Marque\Console\InstallCommand;

/**
 * marque/marque exists to make `composer require` do something visible.
 *
 * The package requires only what every deployment needs — trove, threepio,
 * deck, usarrs — and carries `marque:install`, which interviews the operator
 * and adds the rest. It deliberately is NOT a composer metapackage: a
 * metapackage cannot ship code, and shipping the installer is the entire
 * reason this package exists (job #10539 planned the codeless version and
 * could not host a command).
 *
 * Nothing else is registered here. This package binds no routes, ships no
 * migrations and owns no models; it is a front door, not a layer.
 */
class MarqueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }
}
