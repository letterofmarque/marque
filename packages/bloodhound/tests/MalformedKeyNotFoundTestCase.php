<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/** routes.malformed_key = 'not_found': the router's 404, the default before 9.0 (#11070). */
class MalformedKeyNotFoundTestCase extends RoutingTestCase
{
    protected array $routeConfig = [
        'malformed_key' => 'not_found',
    ];
}
