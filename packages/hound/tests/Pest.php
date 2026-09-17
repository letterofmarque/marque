<?php

declare(strict_types=1);

use Marque\Hound\Tests\CustomPathTestCase;
use Marque\Hound\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

// Job #10649: configurable announce paths. Its own directory because the config
// must be set before boot (routes register in the provider), which needs a
// dedicated TestCase, and Pest binds one test case per directory.
pest()->extend(CustomPathTestCase::class)->in('RoutingPath');
