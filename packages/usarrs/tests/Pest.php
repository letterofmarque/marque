<?php

declare(strict_types=1);

use Marque\Usarrs\Tests\ManageAuthDisabledTestCase;
use Marque\Usarrs\Tests\TestCase;
use Marque\Usarrs\Tests\TrackerBoundTestCase;

// manage_auth=false flips route/component registration at boot, so it needs
// its own TestCase subclass (see ManageAuthDisabledTestCase) rather than a
// runtime config()->set() inside a test. Pest binds one TestCase per file
// by directory-prefix match, with no "more specific path wins" resolution —
// two overlapping in() calls on the same file throw. So Feature's broad
// binding explicitly excludes this subdirectory instead of covering it and
// losing to a second, conflicting bind.
pest()->extend(TestCase::class)->in(
    'Unit',
    ...array_filter(
        glob(__DIR__.'/Feature/*'),
        fn (string $path) => ! in_array(basename($path), ['ManageAuthDisabled', 'TrackerBound'], true),
    ),
);

pest()->extend(ManageAuthDisabledTestCase::class)->in('Feature/ManageAuthDisabled');

// A tracker bound before boot, so the panels usarrs registers on that
// capability are decided the way they would be on a real install.
pest()->extend(TrackerBoundTestCase::class)->in('Feature/TrackerBound');
