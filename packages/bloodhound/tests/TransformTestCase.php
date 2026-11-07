<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/**
 * Two legacy endpoints of the same shape, announce.php?passkey=<hex> and
 * announce2.php?passkey=<hex>, upper or lower case: the first names a transform
 * (LowercaseKeyTransform), the second doesn't, so a test can show what the
 * transform did (Job #109 step 2). Set before boot, see RoutingTestCase.
 */
class TransformTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $legacy = [
            'key_source' => 'query',
            'key_parameter' => 'passkey',
            'key_pattern' => '[0-9a-fA-F]{32}',
        ];

        $app['config']->set('bloodhound.endpoints', [
            'legacy' => $legacy + ['announce_path' => 'announce.php', 'scrape_path' => 'scrape.php', 'transform' => LowercaseKeyTransform::class],
            'plain' => $legacy + ['announce_path' => 'announce2.php', 'scrape_path' => 'scrape2.php'],
        ]);
    }
}
