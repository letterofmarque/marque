<?php

declare(strict_types=1);

namespace Marque\Parley\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Marque\Ise\IseServiceProvider;
use Marque\Parley\ParleyServiceProvider;
use Marque\SquidInk\SquidInkServiceProvider;
use Marque\Trove\TroveServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Every dependency is listed explicitly: Laravel's package auto-discovery
     * does not run under Testbench, so a provider left out here is simply
     * absent. guise's suite broke exactly this way on a missing
     * IseServiceProvider.
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            TroveServiceProvider::class,
            IseServiceProvider::class,
            SquidInkServiceProvider::class,
            ParleyServiceProvider::class,
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
            ],
        });

        $app['config']->set('trove.user_model', TestUser::class);
        $app['config']->set('auth.providers.users.model', TestUser::class);

        // The forum's full-page components render through parley's own
        // layout, which defaults to ise::layouts.app — that pulls in Laravel's
        // Vite helper, which has no manifest under Testbench. A minimal test
        // layout sidesteps it, same fix guise's own suite already needed.
        $app['view']->addNamespace('parley-test', __DIR__.'/views');
        $app['config']->set('parley.layout', 'parley-test::layouts.app');

        // Rendering is squidink's job; caching it would only obscure test
        // failures behind a stale render.
        $app['config']->set('squidink.cache.enabled', false);

        // SQLite ships with foreign key enforcement OFF, so cascadeOnDelete and
        // nullOnDelete silently do nothing under test unless this is set — the
        // constraints exist in the schema and are never exercised. Turning it on
        // is what makes the referential behaviour in these tests mean anything.
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Livewire' => Livewire::class,
        ];
    }
}
