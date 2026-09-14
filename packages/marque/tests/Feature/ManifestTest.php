<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Tests\TestCase;

/**
 * The require block is a security boundary, not a packaging preference, so it
 * is asserted rather than documented.
 *
 * hound registers `Route::get('announce')` unconditionally through
 * loadRoutesFrom, with no announce key and no auth. If marque/marque required
 * the whole suite, every private tracker would carry a keyless open announce
 * endpoint beside its authenticated one and ratio accounting would be
 * bypassable by anyone who guessed the URL. The wrong tracker package has to be
 * ABSENT, which means this package requires only what every deployment needs
 * and the installer adds the rest.
 *
 * A future edit that "tidies up" by adding bloodhound or guise here should fail
 * a test, not merely contradict a comment.
 */
final class ManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = __DIR__.'/../../composer.json';

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, 'composer.json is not valid JSON');

        return $decoded;
    }

    public function test_it_requires_every_package_a_deployment_always_needs(): void
    {
        $require = $this->manifest()['require'];

        foreach (['marque/trove', 'marque/threepio', 'marque/deck', 'marque/usarrs'] as $package) {
            $this->assertArrayHasKey(
                $package,
                $require,
                "{$package} is needed by every deployment and must be required here",
            );
        }
    }

    public function test_it_requires_no_package_the_installer_should_choose(): void
    {
        $require = $this->manifest()['require'];

        // bloodhound/hound are the two that matter: installing both gives a
        // private tracker a keyless announce endpoint. guise/disguise and the
        // optional extras are listed for the same reason — the operator picks
        // them, so requiring them here would take the choice away.
        $chosen = [
            'marque/bloodhound',
            'marque/hound',
            'marque/guise',
            'marque/disguise',
            'marque/cennad',
            'marque/parley',
            'marque/taxonomy',
            'marque/skipper',
            'marque/squidink',
        ];

        foreach ($chosen as $package) {
            $this->assertArrayNotHasKey(
                $package,
                $require,
                "{$package} is chosen during marque:install and must not be required here",
            );
        }
    }

    public function test_it_is_a_library_so_it_can_ship_the_installer(): void
    {
        // type: metapackage would forbid code, which is precisely why the
        // codeless plan in job #10539 could not host marque:install.
        $this->assertSame('library', $this->manifest()['type']);
    }
}
