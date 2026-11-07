<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/**
 * A legacy endpoint, announce.php?passkey=<32 hex>, whose transform provisions
 * members from the old tracker on first announce (LegacyAccountsTransform),
 * beside the native /announce/{key}. Job #109 step 5. Set before boot.
 */
class ProvisioningTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('bloodhound.endpoints', [
            'legacy' => [
                'announce_path' => 'announce.php',
                'scrape_path' => 'scrape.php',
                'key_source' => 'query',
                'key_parameter' => 'passkey',
                'key_pattern' => '[0-9a-f]{32}',
                'transform' => LegacyAccountsTransform::class,
            ],
        ]);
    }
}
