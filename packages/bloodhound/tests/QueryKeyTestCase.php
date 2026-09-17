<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/** The full TBDev shape: announce.php with ?passkey= in the query string. */
class QueryKeyTestCase extends RoutingTestCase
{
    protected array $routeConfig = [
        'announce_path' => 'announce.php',
        'scrape_path' => 'scrape.php',
        'key_source' => 'query',
        'key_parameter' => 'passkey',
    ];
}
