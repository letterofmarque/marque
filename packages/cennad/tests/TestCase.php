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
        // SQLite in memory by default. Marque is DB-agnostic (docs/why.md) and
        // that claim is only worth anything if it is exercised, so the suite
        // can be pointed at a real engine:
        //
        //   DB_CONNECTION=mysql DB_DATABASE=marque_test composer test
        //
        // A green SQLite run does not prove MySQL works — different engines
        // disagree about index length, strict mode, and aggregate typing.
        $app['config']->set('database.connections.testing', match (env('DB_CONNECTION', 'sqlite')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'marque_test'),
                'username' => env('DB_USERNAME', 'marque'),
                'password' => env('DB_PASSWORD', 'marque'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'marque_test'),
                'username' => env('DB_USERNAME', 'marque'),
                'password' => env('DB_PASSWORD', 'marque'),
                'charset' => 'utf8',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                // SQLite defaults to foreign keys OFF, which silently makes
                // every cascadeOnDelete and constrained() in the schema
                // untested. MySQL and Postgres enforce them unconditionally,
                // so leaving this off means the cheapest engine to run is also
                // the one that proves the least.
                'foreign_key_constraints' => true,
            ],
        });

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
        // RefreshDatabase wraps each test in a transaction. Rebuilding the
        // application abandons that transaction without rolling it back, which
        // SQLite tolerates and Postgres does not — the orphaned transaction
        // holds locks that the next reboot blocks on, and the suite hangs with
        // no output rather than failing.
        //
        // Close it deliberately before the connection is thrown away.
        foreach (array_keys($this->app['db']->getConnections()) as $name) {
            $connection = $this->app['db']->connection($name);

            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            $connection->disconnect();
        }

        $this->pendingConfig = $config;

        $this->refreshApplication();

        // An in-memory SQLite database dies with the application that owned
        // it, so the schema has to be laid down again — same paths, same order
        // as defineDatabaseMigrations().
        //
        // A real engine keeps its tables across the reboot, and re-running the
        // migrations there conflicts with the schema already present rather
        // than recreating it. Only rebuild when the database was ephemeral.
        if ($this->app['config']->get('database.connections.testing.driver') === 'sqlite') {
            $this->loadMigrationsFrom(__DIR__.'/migrations');
            $this->loadMigrationsFrom(__DIR__.'/../../trove/database/migrations');
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        // The local users/torrents fixtures must load BEFORE the package
        // migrations, which add columns to those tables and foreign-key
        // against them. loadMigrationsFrom order wins over filename order, so
        // the 0001_ prefix does not save us. SQLite does not enforce foreign
        // keys by default and so never complained; MySQL refuses outright.
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../trove/database/migrations');
    }
}
