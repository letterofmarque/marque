<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Marque\Trove\Contracts\SeedboxesInterface;

// A tracker that measures speeds binds trove's SeedboxesInterface in its own
// register(), before usarrs boots and decides whether to advertise the admin
// screen. So the fake goes in defineEnvironment(), as TrackerBoundTestCase's
// does.
abstract class SeedboxesBoundTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->singleton(SeedboxesInterface::class, FakeSeedboxes::class);
    }
}
