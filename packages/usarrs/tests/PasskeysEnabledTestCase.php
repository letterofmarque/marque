<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Laravel\Passkeys\Passkeys;

// passkeys.enabled=true from the moment configuration loads — as an app's own
// config file would have it — because usarrs decides in register() how
// laravel/passkeys' routes are wired, and Testbench runs defineEnvironment()
// only after the providers have registered.
//
// And laravel/passkeys' own routes registered. Fortify 1.39 suppresses them in
// its register() (Passkeys::ignoreRoutes()) to serve its own instead, which
// usarrs in turn suppresses — so on current Fortify they don't exist at all
// (#10883). Older Fortify within usarrs' ^1.30 floor leaves them registered,
// and that is where their middleware matters. defineEnvironment() runs after
// every register() and before any boot(), so restoring the flag there puts
// this suite in that world.
abstract class PasskeysEnabledTestCase extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('usarrs.passkeys.enabled', true);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        (new \ReflectionProperty(Passkeys::class, 'registersRoutes'))->setValue(null, true);
    }
}
