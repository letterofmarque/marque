<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\ComposerRunner;
use Marque\Marque\Tests\TestCase;

/**
 * A failed require has to reach the operator intact.
 *
 * Composer's resolution errors name the exact constraint that could not be
 * satisfied, which is the single most useful thing on the screen when an
 * install fails. Summarising it into "composer failed" would throw away the
 * only actionable part — the guise/parley break on 2026-09-11 was diagnosed
 * from precisely that text.
 */
final class ComposerRunnerTest extends TestCase
{
    public function test_it_does_nothing_and_succeeds_when_no_packages_were_chosen(): void
    {
        $runner = new ComposerRunner(sys_get_temp_dir());

        $result = $runner->require([]);

        $this->assertTrue($result->successful);
    }

    public function test_a_failure_surfaces_composer_own_output(): void
    {
        $runner = new FakeComposerRunner(sys_get_temp_dir());

        $result = $runner->require(['marque/nonexistent']);

        $this->assertFalse($result->successful);
        $this->assertStringContainsString('could not be resolved', $result->output);
    }

    public function test_output_is_streamed_as_it_arrives(): void
    {
        $seen = [];

        $runner = new FakeComposerRunner(sys_get_temp_dir());
        $runner->require(['marque/nonexistent'], function (string $chunk) use (&$seen): void {
            $seen[] = $chunk;
        });

        // Silence during a long require reads as a hang, and an operator who
        // cannot see progress kills it.
        $this->assertNotEmpty($seen);
    }
}

/**
 * Drives a real subprocess so the streaming and exit-code handling are
 * genuinely exercised, without depending on the network or on composer's
 * behaviour against a package that may exist one day.
 */
final class FakeComposerRunner extends ComposerRunner
{
    protected function composerBinary(): string
    {
        return __DIR__.'/../fixtures/failing-composer';
    }
}
