<?php

declare(strict_types=1);

namespace Marque\Hound\Tests;

/**
 * A TestCase serving announce and scrape at TBDev-style .php paths.
 *
 * Routes register in HoundServiceProvider::boot(), so the config has to be in
 * place before the application boots. defineEnvironment is the only hook early
 * enough — set from a test body or a beforeEach, the routes are already bound to
 * the defaults and the assertions prove nothing.
 */
class CustomPathTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('hound.routes.announce_path', 'announce.php');
        $app['config']->set('hound.routes.scrape_path', 'scrape.php');
    }
}
