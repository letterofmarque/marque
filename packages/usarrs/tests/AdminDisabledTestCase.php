<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

// usarrs registers its admin screens and the Admin nav entry in boot(), so
// admin.enabled has to be false before the app is created, not set inside a
// test body (#10793).
abstract class AdminDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('usarrs.admin.enabled', false);
    }
}
