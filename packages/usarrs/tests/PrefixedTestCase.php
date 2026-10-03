<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

// usarrs mounted under a prefix (usarrs.prefix), set before boot because the
// routes are registered then.
abstract class PrefixedTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('usarrs.prefix', 'members');
    }
}
