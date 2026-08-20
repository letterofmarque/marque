<?php

declare(strict_types=1);

namespace Marque\Parley\Tests;

/**
 * The same test app as TestCase, with the forum switched off.
 *
 * Setting config('parley.forum.enabled') mid-test does not work: route
 * registration happens once, in ParleyServiceProvider::boot(), which has
 * already run by the time a test body executes. Testbench has no supported
 * "refresh the app with different config" call in this version — a separate
 * TestCase subclass that sets the env var before the app boots is what
 * actually exercises the toggle-off path, mirroring guise's
 * TestCaseWithParley split.
 */
abstract class TestCaseWithForumDisabled extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('parley.forum.enabled', false);
    }
}
