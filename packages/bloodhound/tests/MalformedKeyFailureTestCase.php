<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/** A malformed key in the path answered with a bencoded failure, not a 404 (#11070). */
class MalformedKeyFailureTestCase extends RoutingTestCase
{
    protected array $routeConfig = [
        'malformed_key' => 'failure',
    ];
}
