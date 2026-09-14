<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\PackageSelection;
use Marque\Marque\Tests\TestCase;

/**
 * Answers in, package names out.
 *
 * This is where the security constraint actually lives. hound registers
 * `Route::get('announce')` unconditionally with no key and no auth, so a
 * private tracker that merely has hound in vendor/ carries a keyless announce
 * endpoint beside its authenticated one and ratio accounting is bypassable.
 * The selection must therefore be exclusive, and that is asserted rather than
 * commented.
 */
final class PackageSelectionTest extends TestCase
{
    public function test_a_private_tracker_gets_bloodhound_and_guise(): void
    {
        $packages = PackageSelection::private()->packages();

        $this->assertContains('marque/bloodhound', $packages);
        $this->assertContains('marque/guise', $packages);
    }

    public function test_a_private_tracker_never_gets_the_public_tracker(): void
    {
        $packages = PackageSelection::private()->packages();

        $this->assertNotContains('marque/hound', $packages, 'hound on a private tracker is a keyless announce endpoint');
        $this->assertNotContains('marque/disguise', $packages);
    }

    public function test_a_public_tracker_gets_hound_and_disguise(): void
    {
        $packages = PackageSelection::public()->packages();

        $this->assertContains('marque/hound', $packages);
        $this->assertContains('marque/disguise', $packages);
    }

    public function test_a_public_tracker_never_gets_the_private_tracker(): void
    {
        $packages = PackageSelection::public()->packages();

        $this->assertNotContains('marque/bloodhound', $packages);
        $this->assertNotContains('marque/guise', $packages);
    }

    public function test_the_two_tracker_types_share_no_package(): void
    {
        $overlap = array_intersect(
            PackageSelection::private()->packages(),
            PackageSelection::public()->packages(),
        );

        $this->assertSame([], array_values($overlap), 'the tracker halves must be mutually exclusive');
    }

    public function test_optional_extras_are_added_when_asked_for(): void
    {
        $packages = PackageSelection::private()
            ->withApi()
            ->withForums()
            ->withTaxonomy()
            ->withAdminPanel()
            ->packages();

        $this->assertContains('marque/cennad', $packages);
        $this->assertContains('marque/parley', $packages);
        $this->assertContains('marque/taxonomy', $packages);
        $this->assertContains('marque/skipper', $packages);
    }

    public function test_optional_extras_are_absent_unless_asked_for(): void
    {
        $packages = PackageSelection::private()->packages();

        foreach (['marque/cennad', 'marque/parley', 'marque/taxonomy', 'marque/skipper'] as $extra) {
            $this->assertNotContains($extra, $packages);
        }
    }

    public function test_it_names_only_top_level_packages(): void
    {
        // parley already requires squidink, and every choice already requires
        // trove/threepio/deck/usarrs through marque/marque itself. Naming a
        // transitive dependency in the install list is the drift job #10539
        // called out: the conceptual list stops matching what a user types.
        $packages = PackageSelection::private()->withForums()->packages();

        $this->assertNotContains('marque/squidink', $packages, 'parley already pulls squidink');

        foreach (['marque/trove', 'marque/threepio', 'marque/deck', 'marque/usarrs'] as $core) {
            $this->assertNotContains($core, $packages, 'the core arrives with marque/marque');
        }
    }

    public function test_both_tracker_types_need_redis(): void
    {
        // threepio's PeerService is Redis-backed and every announce goes
        // through it, so this is not a private-tracker concern (job #10700).
        $this->assertTrue(PackageSelection::private()->needsRedis());
        $this->assertTrue(PackageSelection::public()->needsRedis());
    }

    public function test_the_selection_is_stable_and_deduplicated(): void
    {
        $packages = PackageSelection::private()->withForums()->withForums()->packages();

        $this->assertSame(array_values(array_unique($packages)), $packages);
    }
}
