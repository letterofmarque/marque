<?php

declare(strict_types=1);

use Marque\Trove\Tests\TestCase;
use Marque\Trove\Tests\ThirdPartyPanelTestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

// A third-party provider booted alongside trove, so a panel registered from
// boot() is in place before any test runs (Spec #118 criterion 5).
pest()->extend(ThirdPartyPanelTestCase::class)->in('ThirdParty');
