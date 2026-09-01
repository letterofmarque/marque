<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

// Route/component registration under manage_auth happens once at
// ServiceProvider::boot(), before any test body runs — so the config has to
// be set in defineEnvironment(), not via a runtime config()->set() inside a
// test. This subclass exists solely to flip that one flag ahead of boot.
abstract class ManageAuthDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('usarrs.manage_auth', false);
    }
}
