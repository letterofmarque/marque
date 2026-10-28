<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\ComposerResult;
use Marque\Marque\Install\ComposerRunner;
use Marque\Marque\Install\FreshProcess;
use Marque\Marque\Install\InstallAnswers;
use Marque\Marque\Install\InstalledPackages;
use Marque\Marque\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * marque:install --preseed: answered questions aren't asked, the rest are,
 * and an unattended run (--no-interaction) needs every answer up front.
 */
final class InstallCommandPreseedTest extends TestCase
{
    /** @var list<InstallAnswers> */
    public array $handedOff = [];

    /** @var list<list<string>> */
    public array $required = [];

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', '127.0.0.1');

        $this->app->instance(InstalledPackages::class, InstalledPackages::fake([]));

        $test = $this;
        $this->app->bind(ComposerRunner::class, fn () => new class($test) extends ComposerRunner
        {
            public function __construct(private readonly InstallCommandPreseedTest $test)
            {
                parent::__construct('/nowhere');
            }

            public function require(array $packages, ?callable $onOutput = null): ComposerResult
            {
                $this->test->required[] = $packages;

                return new ComposerResult(true, '');
            }
        });

        $this->app->bind(FreshProcess::class, fn () => new class($test) extends FreshProcess
        {
            public function __construct(private readonly InstallCommandPreseedTest $test)
            {
                parent::__construct('/nowhere');
            }

            public function finish(InstallAnswers $answers, callable $onOutput): int
            {
                $this->test->handedOff[] = $answers;

                return 0;
            }
        });

        $this->file = tempnam(sys_get_temp_dir(), 'preseed').'.yml';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /** @param array<string, mixed> $answers */
    private function preseed(array $answers): string
    {
        file_put_contents($this->file, Yaml::dump($answers, 4));

        return $this->file;
    }

    /** @return array<string, mixed> */
    private function complete(): array
    {
        return [
            'tracker' => 'private',
            'extras' => ['api' => false, 'forums' => false, 'taxonomy' => false, 'admin_panel' => true, 'staff_management' => true],
            'site_name' => '',
            'admin' => ['name' => 'Ada', 'email' => 'ada@example.com'],
            'stylesheet' => true,
            'user_model' => true,
            'home_page' => 'dashboard',
        ];
    }

    public function test_a_complete_preseed_installs_unattended(): void
    {
        $this->artisan('marque:install', ['--preseed' => $this->preseed($this->complete()), '--no-interaction' => true])
            ->expectsOutputToContain('(preseed)')
            ->assertSuccessful();

        $answers = $this->handedOff[0];
        $this->assertTrue($answers->selection->isPrivate());
        $this->assertContains('marque/skipper', $answers->selection->packages());
        $this->assertContains('marque/bosun', $answers->selection->packages());
        $this->assertNotContains('marque/cennad', $answers->selection->packages());
        $this->assertSame('ada@example.com', $answers->adminEmail);
    }

    public function test_a_partial_preseed_asks_only_what_it_leaves_out(): void
    {
        $partial = $this->complete();
        unset($partial['extras']['forums'], $partial['admin']);

        $this->artisan('marque:install', ['--preseed' => $this->preseed($partial)])
            ->expectsConfirmation('Add forums and torrent comments?', 'yes')
            ->expectsConfirmation('Create an admin account?', 'no')
            ->assertSuccessful();

        $this->assertContains('marque/parley', $this->handedOff[0]->selection->packages());
        $this->assertNull($this->handedOff[0]->adminEmail);
    }

    public function test_an_unattended_run_with_an_incomplete_preseed_is_refused_before_anything_happens(): void
    {
        $partial = $this->complete();
        unset($partial['home_page'], $partial['extras']['api']);

        $this->artisan('marque:install', ['--preseed' => $this->preseed($partial), '--no-interaction' => true])
            ->expectsOutputToContain("It doesn't answer: home_page, extras.api.")
            ->assertFailed();

        $this->assertSame([], $this->required, 'nothing was installed');
        $this->assertSame([], $this->handedOff);
    }

    public function test_an_invalid_preseed_is_refused_before_anything_happens(): void
    {
        $this->artisan('marque:install', ['--preseed' => $this->preseed(['trakcer' => 'private'])])
            ->expectsOutputToContain("isn't an installer question")
            ->assertFailed();

        $this->assertSame([], $this->required);
    }

    public function test_a_preseed_for_the_other_kind_of_tracker_is_refused_on_a_rerun(): void
    {
        $this->app->instance(InstalledPackages::class, InstalledPackages::fake(['marque/hound', 'marque/disguise']));

        $this->artisan('marque:install', ['--preseed' => $this->preseed($this->complete()), '--no-interaction' => true])
            ->expectsOutputToContain('already a public tracker')
            ->assertFailed();

        $this->assertSame([], $this->required);
    }
}
