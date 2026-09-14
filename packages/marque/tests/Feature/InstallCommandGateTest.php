<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Tests\TestCase;

/**
 * A report nobody acts on is decoration. These drive the command itself.
 */
final class InstallCommandGateTest extends TestCase
{
    private function breakTheDatabase(): void
    {
        config()->set('database.default', 'broken');
        config()->set('database.connections.broken', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 59998,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
        ]);
    }

    public function test_it_exits_non_zero_when_the_environment_is_not_ready(): void
    {
        $this->breakTheDatabase();

        $this->artisan('marque:install')->assertFailed();
    }

    public function test_it_names_the_failing_check_rather_than_failing_vaguely(): void
    {
        $this->breakTheDatabase();

        $this->artisan('marque:install')
            ->expectsOutputToContain('database')
            ->assertFailed();
    }

    public function test_it_stops_before_the_interview(): void
    {
        // The operator must not be asked questions whose answers are about to
        // be thrown away. If the command reached the interview it would block
        // waiting for input and this test would hang rather than fail.
        $this->breakTheDatabase();

        $this->artisan('marque:install')
            ->doesntExpectOutputToContain('tracker')
            ->assertFailed();
    }
}
