<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/**
 * bloodhound booted with ratio_mode = 'seedtime'.
 *
 * Panels register in the provider's boot(), so the mode has to be set before
 * the application boots — config()->set() inside a test is too late and the
 * assertion would pass or fail for the wrong reason. Same constraint the
 * routing TestCases exist for.
 */
class RatioModeSeedtimeTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('bloodhound.ratio_mode', 'seedtime');
    }
}
