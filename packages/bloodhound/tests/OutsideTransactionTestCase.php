<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

/**
 * For tests that cannot run inside the per-test transaction the rest of the
 * suite uses: ones that migrate the schema themselves (rolling back to an
 * older release and forward, as an upgrading install does), since DDL
 * commits on MySQL and MariaDB; and ones that hold a lock from a second
 * connection, which only sees committed rows. Each test starts from a freshly
 * migrated database and leaves one behind, so the suites that follow in the
 * same process find exactly what they expect.
 */
abstract class OutsideTransactionTestCase extends TestCase
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
