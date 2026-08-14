<?php

declare(strict_types=1);

namespace Marque\SquidInk\Tests;

use Marque\SquidInk\SquidInkServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SquidInkServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('squidink.cache.enabled', false);
    }
}
