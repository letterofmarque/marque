<?php

declare(strict_types=1);

use Marque\Skipper\Tests\StaffPrefixTestCase;
use Marque\Skipper\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(StaffPrefixTestCase::class)->in('StaffPrefix');
