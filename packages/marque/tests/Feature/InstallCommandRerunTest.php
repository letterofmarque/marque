<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\InstalledPackages;
use Marque\Marque\Tests\TestCase;

/**
 * The command, re-run over an existing install (#10803).
 *
 * Redis is pointed at a dead port so the command stops at the gate's second
 * pass, right after the interview and before composer — what these assert
 * about is the interview. Mail is set to a real transport so the first pass
 * lets the interview start.
 */
final class InstallCommandRerunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.redis.default', ['host' => '127.0.0.1', 'port' => 59997, 'database' => 0]);
        // The gate refuses the log mailer before the interview; any real
        // transport passes it (nothing is sent before the Redis stop).
        config()->set('mail.default', 'smtp');
    }

    private function installed(array $packages): void
    {
        $this->app->instance(InstalledPackages::class, InstalledPackages::fake($packages));
    }

    public function test_it_does_not_ask_the_tracker_question_when_a_tracker_is_installed(): void
    {
        $this->installed(['marque/hound', 'marque/disguise']);

        $this->artisan('marque:install')
            ->expectsOutputToContain('public tracker')
            ->expectsConfirmation('Add the REST API?', 'no')
            ->expectsConfirmation('Add forums and torrent comments?', 'no')
            ->expectsConfirmation('Add the content taxonomy engine?', 'no')
            ->expectsConfirmation('Add the admin panel?', 'no')
            ->expectsQuestion('What is this tracker called?', '')
            ->expectsConfirmation('Create an admin account?', 'no')
            ->assertFailed(); // stopped by the dead Redis, after the interview
    }

    public function test_it_does_not_ask_about_extras_already_installed(): void
    {
        $this->installed(['marque/hound', 'marque/disguise', 'marque/cennad', 'marque/skipper']);

        $this->artisan('marque:install')
            ->expectsConfirmation('Add forums and torrent comments?', 'no')
            ->expectsConfirmation('Add the content taxonomy engine?', 'no')
            ->expectsQuestion('What is this tracker called?', '')
            ->expectsConfirmation('Create an admin account?', 'no')
            ->assertFailed();
    }

    public function test_it_refuses_an_install_that_already_holds_both_trackers(): void
    {
        $this->installed(['marque/hound', 'marque/bloodhound']);

        $this->artisan('marque:install')
            ->expectsOutputToContain('both')
            ->doesntExpectOutputToContain('What kind of tracker')
            ->assertFailed();
    }
}
