<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Marque\Usarrs\Tests\Fixtures\AcmePanelServiceProvider;

// A third-party package booted after usarrs. Its panel registers in boot(),
// so it has to be a provider rather than a call inside a test — registering
// from a test body would prove the registry accepts a panel, not that a
// package can contribute one to a dashboard it knows nothing about.
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
