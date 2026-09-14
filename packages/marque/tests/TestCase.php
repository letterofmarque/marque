<?php

declare(strict_types=1);

namespace Marque\Marque\Tests;

use Marque\Marque\MarqueServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Only this package's provider. marque/marque installs the suite for a
     * consumer; it does not boot it here, and the installer is tested by
     * driving the command rather than by standing up a tracker.
     *
     * Laravel's package auto-discovery does not run under Testbench, so a
     * provider left out is simply absent — guise's suite broke exactly that
     * way on a missing DeckServiceProvider.
     */
    protected function getPackageProviders($app): array
    {
        return [
            MarqueServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // No database is touched by this package's own suite: it ships no
        // migrations and no models. The connection is still declared so a
        // cross-engine run (DB_CONNECTION=mysql ...) has somewhere to point
        // rather than failing on a missing config key.
        $app['config']->set('database.default', 'testing');
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
                // every constrained() in the suite untested. On by default so
                // the cheapest engine is not also the one proving least.
                'foreign_key_constraints' => true,
            ],
        });
    }
}
