<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/**
 * A TestCase whose bloodhound.routes config is set BEFORE the app boots.
 *
 * Routes are registered in BloodhoundServiceProvider::boot(), so config set from
 * inside a test body — or even from a beforeEach — arrives too late: the routes
 * are already bound to the old values, and an assertion then passes or fails for
 * a reason unrelated to what it claims to test.
 *
 * Testbench's defineEnvironment hook runs during bootstrap, which is the only
 * point early enough. Subclasses declare their overrides in $routeConfig and get
 * an application whose route table genuinely reflects them.
 */
abstract class RoutingTestCase extends TestCase
{
    /**
     * Overrides merged into bloodhound.routes before boot.
     *
     * @var array<string, string>
     */
    protected array $routeConfig = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach ($this->routeConfig as $key => $value) {
            $app['config']->set("bloodhound.routes.{$key}", $value);
        }
    }
}
