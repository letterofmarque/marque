<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use Throwable;

/**
 * Exercises what the installer just built.
 *
 * Spec #115 exists because "files written" and "working" were not the same
 * thing: the suite registered thirty-odd routes, every file was where it
 * belonged, and the app still showed Laravel's welcome page with a fatal on
 * its main listing. An installer that reported success from the fact it had
 * finished writing would have been reporting success on exactly that state.
 *
 * So this runs last, and a failure here is loud even though everything was
 * written.
 */
final class SelfVerification
{
    /** @var list<CheckResult> */
    private array $results = [];

    /**
     * @param  callable(): int  $probe  returns an HTTP status code
     * @param  bool  $mustReachApp  when true, a 3xx is a FAILURE rather than a
     *                              pass — see acceptable()
     */
    public function check(string $name, callable $probe, bool $mustReachApp = false): CheckResult
    {
        try {
            $status = $probe();

            if ($mustReachApp && $status >= 300 && $status < 400) {
                // The request never got past middleware, so the page itself
                // was never exercised and this check proved nothing.
                $result = new CheckResult(
                    $name,
                    false,
                    "redirected ({$status}) — the page was never reached, so nothing was proven",
                );
            } else {
                $result = $this->acceptable($status)
                    ? new CheckResult($name, true, (string) $status)
                    : new CheckResult($name, false, "responded {$status}");
            }
        } catch (Throwable $e) {
            // The operator needs the actual error. "Verification failed" tells
            // them nothing; "Call to undefined method User::isUploader()" tells
            // them exactly which stage to re-run.
            $result = new CheckResult($name, false, $this->explain($e));
        }

        $this->results[] = $result;

        return $result;
    }

    /**
     * 2xx and 3xx both count.
     *
     * A private tracker with no signed-in session answers /torrents with a 302
     * to login, and that is the correct behaviour — treating it as a failure
     * would make the check cry wolf on a healthy install, and a check that
     * cries wolf gets ignored.
     */
    private function acceptable(int $status): bool
    {
        return $status >= 200 && $status < 400;
    }

    public function failed(): bool
    {
        return $this->failures() !== [];
    }

    /** @return list<CheckResult> */
    public function failures(): array
    {
        return array_values(array_filter($this->results, fn (CheckResult $r): bool => ! $r->passed));
    }

    /** @return list<CheckResult> */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * Some failures have an obvious remedy the operator should be handed
     * rather than left to infer from a stack trace.
     */
    private function explain(Throwable $e): string
    {
        $message = $this->firstLine($e->getMessage());

        // The cold-run failure: the installer wires Tailwind's sources, but
        // the assets have never been compiled, so every page 500s on a missing
        // manifest. One npm command away, and worth saying so.
        if (str_contains($message, 'Vite manifest not found')) {
            return $message."\n    Run `npm install && npm run build` (or `npm run dev`) to compile your assets.";
        }

        return $message;
    }

    private function firstLine(string $message): string
    {
        return trim(explode("\n", $message)[0]);
    }
}
