<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

// usarrs' dashboard moved off /dashboard (usarrs.dashboard.path), set before
// boot because the routes are registered then (#11070).
abstract class DashboardPathTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('usarrs.dashboard.path', 'members/home');
    }
}
