<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Illuminate\Testing\PendingCommand;
use Marque\Marque\Install\ComposerResult;
use Marque\Marque\Install\ComposerRunner;
use Marque\Marque\Install\FreshProcess;
use Marque\Marque\Install\InstallAnswers;
use Marque\Marque\Install\InstalledPackages;
use Marque\Marque\Tests\TestCase;

/**
 * The install is finished in a fresh process (Job #131).
 *
 * composer require runs in a subprocess, but the command that started it keeps
 * the autoloader and service providers it booted with — so on a first run
 * nothing it had just installed was loaded: bloodhound's migrations never ran
 * and guise's routes didn't exist (/torrents 404). Measured by a cold run,
 * 2026-10-01. The second cold run found the same inside a fresh process that
 * patched the User model after booting, so the file edits now happen first.
 */
final class InstallCommandHandoffTest extends TestCase
{
    /** @var list<InstallAnswers> */
    public array $handedOff = [];

    public int $freshProcessResult = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', '127.0.0.1');

        $this->app->instance(InstalledPackages::class, InstalledPackages::fake([]));

        $this->app->bind(ComposerRunner::class, fn () => new class('/nowhere') extends ComposerRunner
        {
            public function require(array $packages, ?callable $onOutput = null): ComposerResult
            {
                return new ComposerResult(true, '');
            }
        });

        $test = $this;
        $this->app->bind(FreshProcess::class, fn () => new class($test) extends FreshProcess
        {
            public function __construct(private readonly InstallCommandHandoffTest $test)
            {
                parent::__construct('/nowhere');
            }

            public function finish(InstallAnswers $answers, callable $onOutput): int
            {
                $this->test->handedOff[] = $answers;

                return $this->test->freshProcessResult;
            }
        });
    }

    private function runFreshInstall(): PendingCommand
    {
        return $this->artisan('marque:install')
            ->expectsQuestion('What kind of tracker is this?', 'private')
            ->expectsConfirmation('Add the REST API?', 'yes')
            ->expectsConfirmation('Add forums and torrent comments?', 'no')
            ->expectsConfirmation('Add the content taxonomy engine?', 'no')
            ->expectsConfirmation('Add the admin panel?', 'yes')
            ->expectsQuestion('What is this tracker called?', '')
            ->expectsConfirmation('Create an admin account?', 'yes')
            ->expectsQuestion('What is the admin called?', 'Ada')
            ->expectsQuestion('What email address?', 'ada@example.com');
    }

    public function test_after_installing_packages_it_finishes_in_a_fresh_process(): void
    {
        $this->runFreshInstall()->assertSuccessful();

        $this->assertCount(1, $this->handedOff, 'the rest of the install ran in a fresh process');
    }

    public function test_the_fresh_process_gets_every_answer(): void
    {
        $this->runFreshInstall()->run();

        $answers = $this->handedOff[0];
        $this->assertTrue($answers->selection->isPrivate());
        $this->assertContains('marque/cennad', $answers->selection->packages());
        $this->assertContains('marque/skipper', $answers->selection->packages());
        $this->assertNotContains('marque/parley', $answers->selection->packages());
        $this->assertSame('Ada', $answers->adminName);
        $this->assertSame('ada@example.com', $answers->adminEmail);
    }

    public function test_it_reports_the_fresh_process_failing(): void
    {
        $this->freshProcessResult = 1;

        $this->runFreshInstall()->assertFailed();
    }

    public function test_a_rerun_with_nothing_new_still_finishes_in_a_fresh_process(): void
    {
        // A re-run can patch the User model too (one declined the first time),
        // and the process that patched it has the old class loaded — so the
        // finish never runs in place.
        $this->app->instance(InstalledPackages::class, InstalledPackages::fake(['marque/hound', 'marque/disguise']));

        $this->artisan('marque:install')
            ->expectsConfirmation('Add the REST API?', 'no')
            ->expectsConfirmation('Add forums and torrent comments?', 'no')
            ->expectsConfirmation('Add the content taxonomy engine?', 'no')
            ->expectsConfirmation('Add the admin panel?', 'no')
            ->expectsQuestion('What is this tracker called?', '')
            ->expectsConfirmation('Create an admin account?', 'no')
            ->expectsOutputToContain('Nothing new to install')
            ->assertSuccessful();

        $this->assertCount(1, $this->handedOff);
        $this->assertFalse($this->handedOff[0]->selection->isPrivate());
    }
}
