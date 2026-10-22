<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\HitAndRunDefault;
use Marque\Marque\Install\PackageSelection;
use Marque\Marque\Tests\TestCase;

/**
 * Job #256: bloodhound ships hit-and-run off, so an existing tracker is not
 * changed under it on upgrade. A tracker the installer builds from nothing
 * has no members yet to surprise, so it starts with the rule on.
 */
final class HitAndRunDefaultTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/marque-hnr-'.uniqid().'.env';
        file_put_contents($this->path, "APP_NAME=Tracker\n");
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.marque-backup'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_a_new_private_tracker_starts_with_hit_and_run_on(): void
    {
        $this->assertTrue((new HitAndRunDefault($this->path))->apply(PackageSelection::private(), freshInstall: true));

        $this->assertStringContainsString('BLOODHOUND_HIT_AND_RUN=true', (string) file_get_contents($this->path));
    }

    public function test_a_public_tracker_gets_nothing(): void
    {
        $this->assertFalse((new HitAndRunDefault($this->path))->apply(PackageSelection::public(), freshInstall: true));

        $this->assertStringNotContainsString('BLOODHOUND_HIT_AND_RUN', (string) file_get_contents($this->path));
    }

    public function test_a_rerun_on_an_existing_tracker_changes_nothing(): void
    {
        $this->assertFalse((new HitAndRunDefault($this->path))->apply(PackageSelection::private(), freshInstall: false));

        $this->assertStringNotContainsString('BLOODHOUND_HIT_AND_RUN', (string) file_get_contents($this->path));
    }

    public function test_an_operators_own_setting_is_left_alone(): void
    {
        file_put_contents($this->path, "APP_NAME=Tracker\nBLOODHOUND_HIT_AND_RUN=false\n");

        $this->assertFalse((new HitAndRunDefault($this->path))->apply(PackageSelection::private(), freshInstall: true));

        $this->assertStringContainsString('BLOODHOUND_HIT_AND_RUN=false', (string) file_get_contents($this->path));
    }
}
