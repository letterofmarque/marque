<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Passkeys\PasskeysServiceProvider;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Marque\Ise\IseServiceProvider;
use Marque\Trove\TroveServiceProvider;
use Marque\Usarrs\UsarrsServiceProvider;
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
            // FortifyServiceProvider is registered explicitly here (not by
            // usarrs' own composer.json alone) so the test suite proves usarrs
            // actively suppresses Fortify's routes, not merely that Fortify
            // was never installed to begin with.
            FortifyServiceProvider::class,
            PasskeysServiceProvider::class,
            UsarrsServiceProvider::class,
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
        $app['config']->set('usarrs.layout', 'usarrs-test::layouts.app');
        $app['config']->set('auth.providers.users.model', TestUser::class);

        // Passkeys' own config default (env('PASSKEYS_USER_HANDLE_SECRET',
        // config('app.key'))) is evaluated when its config file is merged
        // during PasskeysServiceProvider::register() — which runs before this
        // method sets app.key above, so the default resolves to null in this
        // harness. Set explicitly rather than relying on load order.
        $app['config']->set('passkeys.user_handle_secret', 'test-secret');
        $app['config']->set('passkeys.relying_party_id', 'localhost');
        $app['config']->set('passkeys.allowed_origins', ['http://localhost']);

        $app['view']->addNamespace('usarrs-test', __DIR__.'/views');

        // Orchestra Testbench's minimal app doesn't run through
        // Illuminate\Foundation\Configuration\Middleware's default alias
        // registration the way a real Laravel app (bootstrap/app.php) does
        // — 'verified' isn't registered automatically, unlike 'signed',
        // which Laravel core wires up independently of that bootstrapper.
        // Registered here so tests can exercise the same 'verified'
        // middleware a real consuming app's own routes/config would use
        // (e.g. config('usarrs.admin_middleware')'s default).
        $app['router']->aliasMiddleware('verified', EnsureEmailIsVerified::class);
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
