<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\SelfVerification;
use Marque\Marque\Tests\TestCase;

/**
 * The installer is not allowed to declare a success it has not checked.
 *
 * Spec #115 exists precisely because "files written" and "working" diverged:
 * the suite registered thirty-odd routes, and the app still showed Laravel's
 * welcome page with a fatal on its main listing. Every file was where it
 * should be. Nothing worked.
 *
 * So the last thing marque:install does is exercise what it built.
 */
final class SelfVerificationTest extends TestCase
{
    public function test_a_route_that_responds_passes(): void
    {
        $result = (new SelfVerification)->check('working', fn () => 200);

        $this->assertTrue($result->passed);
        $this->assertSame('working', $result->name);
    }

    public function test_a_redirect_passes(): void
    {
        // 302 is correct for /torrents on a private tracker with no session —
        // it redirects to login. Treating that as a failure would make the
        // check cry wolf on a correct install.
        $result = (new SelfVerification)->check('redirecting', fn () => 302);

        $this->assertTrue($result->passed);
    }

    public function test_a_server_error_fails(): void
    {
        $result = (new SelfVerification)->check('broken', fn () => 500);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('500', $result->detail);
    }

    public function test_a_missing_route_fails(): void
    {
        // 404 means the package did not register what it should have.
        $result = (new SelfVerification)->check('absent', fn () => 404);

        $this->assertFalse($result->passed);
    }

    public function test_a_thrown_error_is_caught_and_reported(): void
    {
        $result = (new SelfVerification)->check('fatal', function (): int {
            throw new \RuntimeException('Call to undefined method App\Models\User::isUploader()');
        });

        $this->assertFalse($result->passed);

        // The whole point: the operator sees the actual fatal, not
        // "verification failed".
        $this->assertStringContainsString('isUploader', $result->detail);
    }

    public function test_the_run_is_fatal_when_any_check_fails(): void
    {
        $verification = new SelfVerification;
        $verification->check('good', fn () => 200);
        $verification->check('bad', fn () => 500);

        $this->assertTrue($verification->failed());
        $this->assertCount(1, $verification->failures());
    }

    public function test_the_run_is_clean_when_everything_passes(): void
    {
        $verification = new SelfVerification;
        $verification->check('one', fn () => 200);
        $verification->check('two', fn () => 302);

        $this->assertFalse($verification->failed());
        $this->assertSame([], $verification->failures());
    }

    public function test_results_are_returned_in_order(): void
    {
        $verification = new SelfVerification;
        $verification->check('first', fn () => 200);
        $verification->check('second', fn () => 200);

        $names = array_map(fn ($r): string => $r->name, $verification->results());

        $this->assertSame(['first', 'second'], $names);
    }

    public function test_a_redirect_to_login_is_not_proof_the_page_works(): void
    {
        // Found 2026-09-14 by the negative test CP7 mandates: reverting the
        // User model to stock left /torrents fatalling on isUploader(), and
        // self-verification still passed — because auth middleware 302s to
        // login BEFORE the controller runs, so the probe never reached the
        // broken code. A 302 is indistinguishable from a healthy private
        // tracker.
        //
        // So a check may declare that it needs an authenticated request, and
        // an unauthenticated redirect on such a check is a failure rather than
        // a pass: it means the probe proved nothing.
        $result = (new SelfVerification)->check('/torrents', fn (): int => 302, mustReachApp: true);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('redirect', strtolower($result->detail));
    }

    public function test_an_authenticated_check_passes_on_a_real_response(): void
    {
        $result = (new SelfVerification)->check('/torrents', fn (): int => 200, mustReachApp: true);

        $this->assertTrue($result->passed);
    }
}
