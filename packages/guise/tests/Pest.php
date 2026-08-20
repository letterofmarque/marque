<?php

declare(strict_types=1);

use Marque\Guise\Tests\TestCase;
use Marque\Guise\Tests\TestCaseWithParley;

pest()->extend(TestCase::class)->in('Feature');

// A sibling directory, not Feature/Integration — Pest's directory bindings
// don't nest/override, two ->in() calls on overlapping paths conflict
// whichever order they register in. Scoped separately because these tests
// need parley actually installed and booted, unlike the rest of the Feature
// suite which deliberately runs without it — see TestCaseWithParley's
// docblock.
pest()->extend(TestCaseWithParley::class)->in('Integration');
