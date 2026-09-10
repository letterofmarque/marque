<?php

declare(strict_types=1);

namespace Marque\Skipper\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Marque\Deck\DeckServiceProvider;
use Marque\Skipper\SkipperServiceProvider;
use Marque\Trove\TroveServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Every dependency is listed explicitly: Laravel's package auto-discovery
     * does not run under Testbench, so a provider left out here is simply
     * absent. guise's suite broke exactly this way on a missing
     * DeckServiceProvider.
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            TroveServiceProvider::class,
            DeckServiceProvider::class,
            SkipperServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // trove reaches the host's user model through config rather than
        // shipping one, and the panel authorises against its role.
        $app['config']->set('trove.user_model', TestUser::class);
        $app['config']->set('auth.providers.users.model', TestUser::class);

        // The panel renders through skipper.layout, which defaults to
        // deck::layouts.app — that pulls in Laravel's Vite helper, which has no
        // manifest under Testbench. A minimal test layout sidesteps it; the same
        // fix guise and parley already needed, and the reason the layout is
        // configurable in the first place.
        $app['view']->addNamespace('skipper-test', __DIR__.'/views');
        $app['config']->set('skipper.layout', 'skipper-test::layouts.app');

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
            'mariadb' => [
                'driver' => 'mariadb',
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
    }

    /**
     * Stand-in routes for the screens tests register.
     *
     * Bound here rather than inside a test body: a Route::get() called mid-test
     * is not visible to router->has() until a request cycle has run, which the
     * tests that instantiate Panel directly never trigger. CP6 registers these
     * for real; here they only need to exist.
     */
    protected function defineWebRoutes($router): void
    {
        // A real Livewire component for screens under test to resolve to.
        // Registered here rather than in defineEnvironmentSetUp, which runs
        // before Livewire's own provider boots. Routing looks the registered
        // component up, so a name resolving to nothing would test the gate and
        // not the routing.
        Livewire::component('skipper-test-screen', TestScreen::class);

        foreach (['users', 'a', 'b', 'z', 'loose', 'real', 'admin-only', 'mod-ok'] as $id) {
            $router->get("admin/{$id}", fn () => $id)->name("admin.{$id}");
        }

        // `login` belongs to the host app; no Marque package ships it, and the
        // auth middleware redirects there.
        $router->get('login', fn () => 'login')->name('login');
    }

    protected function defineDatabaseMigrations(): void
    {
        // The fixture first: trove's migrations add columns to `users`, which
        // is the host app's table and which no package in the suite ships.
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
