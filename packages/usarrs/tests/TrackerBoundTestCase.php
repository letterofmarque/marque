<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Marque\Trove\Contracts\TrackerStatsInterface;

// usarrs registers its tracker panels in boot(), and only when a tracker is
// bound (Spec #118, Build #108 CP3). A tracker binds in its own register(),
// which runs before any provider boots — so the fake has to be in place in
// defineEnvironment() too. app()->instance() inside a test would be too late:
// the registry would already have been decided without it.
abstract class TrackerBoundTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->singleton(TrackerStatsInterface::class, FakeTrackerStats::class);
    }
}
