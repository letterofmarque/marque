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

    /**
     * Config applied on the next application boot. See rebootWithConfig().
     *
     * @var array<string, mixed>
     */
    protected array $pendingConfig = [];

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
        // 'auth' rather than 'auth:api' — the suite authenticates against the
        // session guard below. The shipped default is ['api', 'auth:api'].
        $app['config']->set('cennad.read_middleware', ['api', 'auth']);
        $app['config']->set('cennad.write_middleware', ['api', 'auth']);
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web.driver', 'session');
        $app['config']->set('auth.guards.web.provider', 'users');
        $app['config']->set('auth.providers.users.driver', 'eloquent');
        $app['config']->set('auth.providers.users.model', TestUser::class);

        foreach ($this->pendingConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /**
     * Rebuild the application with extra config applied.
     *
     * Route middleware is bound when routes are registered at boot, so a
     * runtime config()->set() has no effect on it. Tests that vary the
     * middleware config need the container rebuilt around the new values.
     *
     * @param  array<string, mixed>  $config
     */
    protected function rebootWithConfig(array $config): void
    {
        $this->pendingConfig = $config;

        $this->refreshApplication();

        // The rebooted app gets a fresh :memory: database, so the schema has
        // to be laid down again — same paths, same order as
        // defineDatabaseMigrations().
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../trove/database/migrations');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../trove/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
