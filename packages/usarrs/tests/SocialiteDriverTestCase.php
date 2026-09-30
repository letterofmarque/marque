<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

// auth_driver=socialite, set before boot. Which OAuth routes exist is decided
// when routes/auth.php is loaded (Spec #142: OAuth only under socialite mode),
// so a runtime config()->set() in a test would be too late to see it.
abstract class SocialiteDriverTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('usarrs.auth_driver', 'socialite');
    }
}
