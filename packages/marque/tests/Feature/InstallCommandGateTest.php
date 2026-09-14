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

    public function test_it_stops_before_asking_the_operator_anything(): void
    {
        // The database is required regardless of every answer, so discovering
        // it is unreachable AFTER a page of questions wastes the operator's
        // time. It is therefore checked in a first pass, before any prompt.
        //
        // Redis is deliberately NOT in that pass: whether it is needed depends
        // on whether the deployment announces, which only the interview can
        // say. Splitting the gate is what lets both hold at once.
        //
        // If the command reached a prompt here the test would hang on input
        // rather than fail, so reaching the assertion at all is the proof.
        $this->breakTheDatabase();

        $this->artisan('marque:install')
            ->doesntExpectOutputToContain('What kind of tracker')
            ->assertFailed();
    }

    public function test_it_does_not_claim_nothing_changed_after_writing_env(): void
    {
        // setSiteName() writes .env inside the interview, which sits between
        // the gate's two passes. If a later check fails, the refusal must not
        // claim nothing was changed when .env already was.
        $this->assertStringNotContainsString(
            'Nothing has been changed',
            $this->redisRefusalWording(),
            'the Redis refusal must not claim nothing changed — the interview may have written APP_NAME',
        );
    }

    private function redisRefusalWording(): string
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Console/InstallCommand.php');

        // The wording used on the post-interview refusal path.
        preg_match('/runConditional.*?return self::FAILURE;/s', $source, $m);

        return $m[0] ?? '';
    }
}
