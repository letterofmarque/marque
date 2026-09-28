<?php

declare(strict_types=1);

namespace Marque\Trove\Tests;

use Marque\Trove\Tests\Fixtures\AcmePanelServiceProvider;

/**
 * trove plus one third-party provider that registers a dashboard panel, and
 * nothing else — no usarrs, no Livewire. The registration happens in the
 * fixture's boot(), so it has to be a provider rather than a call inside a
 * test: registering from a test body would prove the registry accepts a
 * panel, not that a package can boot while contributing one.
 */
abstract class ThirdPartyPanelTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AcmePanelServiceProvider::class,
        ];
    }
}
