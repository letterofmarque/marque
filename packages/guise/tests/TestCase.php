<?php

declare(strict_types=1);

namespace Marque\Guise\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Marque\Guise\GuiseServiceProvider;
use Marque\Ise\IseServiceProvider;
use Marque\Trove\TroveServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            TroveServiceProvider::class,
            IseServiceProvider::class,
            GuiseServiceProvider::class,
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
        $app['config']->set('guise.layout', 'guise-test::layouts.app');
        $app['config']->set('guise.middleware', ['web', 'auth']);
        $app['config']->set('auth.providers.users.model', TestUser::class);

        // Register test views namespace
        $app['view']->addNamespace('guise-test', __DIR__.'/views');
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

    protected function getPackageAliases($app): array
    {
        return [
            'Livewire' => Livewire::class,
        ];
    }

    protected function defineRoutes($router): void
    {
        $router->get('login', fn () => 'login')->name('login');
    }
}
