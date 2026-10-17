<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/**
 * For tests that run migrations themselves: rolling the schema back to an
 * older release and migrating forward, the way an upgrading install does.
 *
 * DDL commits on MySQL and MariaDB, so these cannot sit inside the per-test
 * transaction the rest of the suite uses. Each test starts from a freshly
 * migrated database and leaves one behind, so the suites that follow in the
 * same process find exactly what they expect.
 */
abstract class UpgradePathTestCase extends TestCase
{
    public function refreshDatabase(): void
    {
        $this->rebuildSchema();

        $this->beforeApplicationDestroyed(fn () => $this->rebuildSchema());
    }

    /**
     * Not migrate:fresh. Testbench runs the fixtures' migrations (the users
     * table the packages alter) directly rather than registering their path,
     * so a fresh migrate knows only the packages' paths and never builds
     * users. Wipe, migrate the fixtures by path, then everything registered.
     */
    private function rebuildSchema(): void
    {
        $this->artisan('db:wipe', ['--force' => true]);
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/migrations'), '--realpath' => true, '--force' => true]);
        $this->artisan('migrate', ['--force' => true]);
    }
}
