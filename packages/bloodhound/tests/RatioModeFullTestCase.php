<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/**
 * bloodhound booted with ratio_mode = 'full'.
 *
 * The tracker stats binding is made while the application boots, so the mode
 * has to be set before then — config()->set() inside a test is too late, and a
 * binding made conditional on ratio_mode would go unseen (Spec #119). Same
 * constraint the routing TestCases exist for.
 */
class RatioModeFullTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('bloodhound.ratio_mode', 'full');
    }
}
