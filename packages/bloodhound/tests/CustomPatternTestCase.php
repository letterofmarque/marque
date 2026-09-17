<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/** A 40-char hex passkey — narrower alphabet and different length to the default. */
class CustomPatternTestCase extends RoutingTestCase
{
    protected array $routeConfig = [
        'key_pattern' => '[0-9a-f]{40}',
    ];
}
