<?php

declare(strict_types=1);

namespace Marque\Parley\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use Marque\Id\IdServiceProvider;
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
     * IdServiceProvider.
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            TroveServiceProvider::class,
            IdServiceProvider::class,
            SquidInkServiceProvider::class,
            ParleyServiceProvider::class,
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
        $app['config']->set('auth.providers.users.model', TestUser::class);

        // Rendering is squidink's job; caching it would only obscure test
        // failures behind a stale render.
        $app['config']->set('squidink.cache.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Livewire' => \Livewire\Livewire::class,
        ];
    }
}
