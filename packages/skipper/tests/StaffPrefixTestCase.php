<?php

declare(strict_types=1);

namespace Marque\Skipper\Tests;

// skipper.prefix moved off 'admin' before boot, with a package that binds its
// own admin/owned route and screen (#11108).
abstract class StaffPrefixTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), OwnScreenProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('skipper.prefix', 'staff');
    }
}
