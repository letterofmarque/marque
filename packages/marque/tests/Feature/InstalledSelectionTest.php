<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\InstalledPackages;
use Marque\Marque\Install\MixedTrackerInstall;
use Marque\Marque\Install\PackageSelection;
use Marque\Marque\Tests\TestCase;

/**
 * A re-run of marque:install starts from what is already installed (#10803).
 *
 * It used to run the whole interview again, the tracker question defaulting to
 * private — so re-running a public tracker to add the API and pressing Enter
 * composer-required bloodhound and guise beside hound: a keyless announce
 * endpoint beside an authenticated one, the exact mix PackageSelection exists
 * to make impossible.
 */
final class InstalledSelectionTest extends TestCase
{
    public function test_a_fresh_app_has_no_installed_selection(): void
    {
        $this->assertNull(PackageSelection::fromInstalled(InstalledPackages::fake([])));
    }

    public function test_an_installed_public_tracker_stays_public(): void
    {
        $selection = PackageSelection::fromInstalled(InstalledPackages::fake(['marque/hound', 'marque/disguise']));

        $this->assertFalse($selection->isPrivate());
        $this->assertNotContains('marque/bloodhound', $selection->withApi()->packages());
        $this->assertNotContains('marque/guise', $selection->withApi()->packages());
    }

    public function test_an_installed_private_tracker_stays_private(): void
    {
        $selection = PackageSelection::fromInstalled(InstalledPackages::fake(['marque/bloodhound']));

        $this->assertTrue($selection->isPrivate());
        $this->assertNotContains('marque/hound', $selection->packages());
    }

    public function test_either_half_of_a_tracker_identifies_it(): void
    {
        // disguise alone (hound removed by hand) is still a public install;
        // adding bloodhound to it would still be the wrong tracker.
        $this->assertFalse(PackageSelection::fromInstalled(InstalledPackages::fake(['marque/disguise']))->isPrivate());
        $this->assertTrue(PackageSelection::fromInstalled(InstalledPackages::fake(['marque/guise']))->isPrivate());
    }

    public function test_an_install_holding_both_halves_is_refused(): void
    {
        $this->expectException(MixedTrackerInstall::class);

        PackageSelection::fromInstalled(InstalledPackages::fake(['marque/hound', 'marque/bloodhound']));
    }

    public function test_installed_extras_are_carried_into_the_selection(): void
    {
        $selection = PackageSelection::fromInstalled(InstalledPackages::fake(['marque/hound', 'marque/disguise', 'marque/cennad', 'marque/skipper']));

        $this->assertTrue($selection->includes('marque/cennad'));
        $this->assertTrue($selection->includes('marque/skipper'));
        $this->assertFalse($selection->includes('marque/parley'));
    }

    public function test_only_what_is_missing_is_required(): void
    {
        $installed = InstalledPackages::fake(['marque/hound', 'marque/disguise', 'marque/cennad']);
        $selection = PackageSelection::fromInstalled($installed)->withForums();

        $this->assertSame(['marque/parley'], $selection->toRequire($installed));
    }
}
