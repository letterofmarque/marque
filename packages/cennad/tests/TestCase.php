<?php

declare(strict_types=1);

namespace Marque\Cennad\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marque\Cennad\CennadServiceProvider;
use Marque\Trove\TroveServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            TroveServiceProvider::class,
            CennadServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('trove.user_model', TestUser::class);
        $app['config']->set('cennad.middleware', ['api', 'auth']);
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web.driver', 'session');
        $app['config']->set('auth.guards.web.provider', 'users');
        $app['config']->set('auth.providers.users.driver', 'eloquent');
        $app['config']->set('auth.providers.users.model', TestUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../trove/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
