<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\EnvironmentCheck;
use Marque\Marque\Tests\TestCase;

/**
 * The environment gate.
 *
 * Everything the installer does afterwards assumes these hold, so a failure
 * here has to stop the run rather than produce a half-wired app that is harder
 * to diagnose than a clean refusal. Mail is the one documented exception: it is
 * routinely deferred on first setup, and deferring it breaks registration and
 * password reset rather than the tracker.
 */
final class EnvironmentCheckTest extends TestCase
{
    private function check(): EnvironmentCheck
    {
        return $this->app->make(EnvironmentCheck::class);
    }

    public function test_a_reachable_database_passes(): void
    {
        // The suite's own sqlite :memory: connection is reachable by definition.
        $report = $this->check()->run(needsRedis: false);

        $this->assertTrue($report->passed('database'));
    }

    public function test_an_unreachable_database_is_fatal(): void
    {
        config()->set('database.default', 'broken');
        config()->set('database.connections.broken', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            // Nothing listens here. A connection refusal is the realistic
            // shape of "you have not set DB_ up yet".
            'port' => 59998,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
        ]);

        $report = $this->check()->run(needsRedis: false);

        $this->assertFalse($report->passed('database'));
        $this->assertTrue($report->isFatal(), 'an unreachable database must stop the install');
    }

    public function test_unreachable_redis_is_fatal_when_the_tracker_needs_it(): void
    {
        config()->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => 59999,
            'database' => 0,
        ]);

        $report = $this->check()->run(needsRedis: true);

        $this->assertFalse($report->passed('redis'));
        $this->assertTrue($report->isFatal());
    }

    public function test_redis_is_not_checked_when_it_is_not_needed(): void
    {
        // An API-only or catalogue install never announces, so an absent Redis
        // is not a problem and must not be reported as one.
        config()->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => 59999,
            'database' => 0,
        ]);

        $report = $this->check()->run(needsRedis: false);

        $this->assertFalse($report->checked('redis'));
        $this->assertFalse($report->isFatal());
    }

    public function test_unconfigured_mail_warns_but_never_blocks(): void
    {
        config()->set('mail.default', 'log');

        $report = $this->check()->run(needsRedis: false);

        $this->assertFalse($report->passed('mail'));
        $this->assertTrue($report->hasWarnings());
        $this->assertFalse($report->isFatal(), 'mail is deferrable and must not stop the install');
    }

    public function test_a_configured_mailer_does_not_warn(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'smtp.example.com');

        $report = $this->check()->run(needsRedis: false);

        $this->assertTrue($report->passed('mail'));
    }

    public function test_the_mail_warning_says_what_actually_breaks(): void
    {
        config()->set('mail.default', 'log');

        $report = $this->check()->run(needsRedis: false);

        // An operator who defers mail needs to know which features are dead
        // until they come back to it, not merely that a check went yellow.
        $warning = implode(' ', $report->warnings());

        $this->assertStringContainsString('registration', strtolower($warning));
        $this->assertStringContainsString('password reset', strtolower($warning));
    }

    public function test_a_fatal_report_names_what_to_fix(): void
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

        $report = $this->check()->run(needsRedis: false);

        $failures = implode(' ', $report->failures());

        $this->assertNotSame('', trim($failures), 'a refusal must explain itself');
        $this->assertStringContainsString('database', strtolower($failures));
    }

    public function test_messages_are_attributable_to_their_check(): void
    {
        // The gate runs in two passes either side of the interview, so the
        // second pass must be able to render only its own lines rather than
        // reprinting what the operator already read.
        config()->set('mail.default', 'log');

        $report = $this->check()->run(needsRedis: false);

        $mail = $report->messagesFor(['mail']);
        $this->assertCount(1, $mail);
        $this->assertFalse($mail[0]['fatal']);
        $this->assertStringContainsString('registration', strtolower($mail[0]['text']));

        $this->assertSame([], $report->messagesFor(['redis']));
    }

    public function test_a_fatal_message_is_marked_fatal(): void
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

        $report = $this->check()->run(needsRedis: false);

        $messages = $report->messagesFor(['database']);

        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]['fatal']);
    }
}
