<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/**
 * A tracker migrating onto Marque, serving its old URLs beside its new ones
 * (Job #109, Spec #117).
 *
 * The primary endpoint is the shipped default, /announce/{key}. Two more are
 * configured before boot (routes are registered in the provider's boot, so
 * config set later is too late, see RoutingTestCase):
 *
 * - `legacy`: announce.php?passkey=<32 hex>, the key in the query string.
 * - `old`: tracker/announce/<32 hex>, the key in the path, to exercise an
 *   additional path-key endpoint's own malformed-key fallback.
 */
class EndpointsTestCase extends TestCase
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
            ],
            'old' => [
                'announce_path' => 'tracker/announce',
                'scrape_path' => 'tracker/scrape',
                'key_source' => 'path',
                'key_parameter' => 'passkey',
                'key_pattern' => '[0-9a-f]{32}',
            ],
        ]);
    }
}
