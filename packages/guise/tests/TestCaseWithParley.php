<?php

declare(strict_types=1);

namespace Marque\Guise\Tests;

use Marque\Parley\ParleyServiceProvider;
use Marque\SquidInk\SquidInkServiceProvider;

/**
 * The same test app as TestCase, with parley actually wired in.
 *
 * A separate subclass rather than adding these providers to TestCase itself,
 * because the whole point of the integration is that guise must boot and
 * serve its torrent pages WITHOUT parley too — TestCase (parley absent)
 * covers that; tests/Integration (parley present) covers the other half, the
 * comment thread rendering when parley IS present. See docs/integration.md
 * and resources/views/torrent/show.blade.php's providerIsLoaded() guard.
 */
abstract class TestCaseWithParley extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            SquidInkServiceProvider::class,
            ParleyServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../parley/database/migrations');
    }
}
