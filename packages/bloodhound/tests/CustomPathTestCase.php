<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/** Announce and scrape moved to TBDev-style .php paths, key still in the path. */
class CustomPathTestCase extends RoutingTestCase
{
    protected array $routeConfig = [
        'announce_path' => 'announce.php',
        'scrape_path' => 'scrape.php',
    ];
}
