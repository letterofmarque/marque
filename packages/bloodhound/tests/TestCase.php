<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marque\Bloodhound\BloodhoundServiceProvider;
use Marque\Threepio\ThreepioServiceProvider;
use Marque\Trove\TroveServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            TroveServiceProvider::class,
            ThreepioServiceProvider::class,
            BloodhoundServiceProvider::class,
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

        $app['config']->set('threepio.redis.connection', 'default');
        $app['config']->set('threepio.redis.prefix', 'marque_test:');
        $app['config']->set('threepio.announce_interval', 1800);
        $app['config']->set('threepio.min_announce_interval', 300);
        $app['config']->set('threepio.peer_expiry', 3600);
        $app['config']->set('bloodhound.queue.enabled', false);

        $app['config']->set('trove.user_model', TestUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
