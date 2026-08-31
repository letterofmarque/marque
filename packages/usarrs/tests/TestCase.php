<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\FortifyServiceProvider;
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
            UsarrsServiceProvider::class,
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
        $app['config']->set('usarrs.layout', 'usarrs-test::layouts.app');
        $app['config']->set('auth.providers.users.model', TestUser::class);

        $app['view']->addNamespace('usarrs-test', __DIR__.'/views');
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
