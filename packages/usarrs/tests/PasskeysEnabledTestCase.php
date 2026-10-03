<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

// passkeys.enabled=true from the moment configuration loads, as an app's own
// config file would have it, because usarrs decides at register time how the
// passkey endpoints are wired, and Testbench runs defineEnvironment() only
// after the providers have registered.
//
// This used to also restore laravel/passkeys' own route registration by
// reflection, to simulate Fortify before 1.39. usarrs now registers the
// endpoints itself on every Fortify version (#10883), so this suite runs the
// real, current-Fortify configuration.
abstract class PasskeysEnabledTestCase extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('usarrs.passkeys.enabled', true);
    }
}
