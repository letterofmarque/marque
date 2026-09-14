<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

/**
 * The result of the environment gate.
 *
 * Deliberately separates "failed" from "fatal". Every check that fails is worth
 * telling the operator about, but only some of them should stop the run —
 * mail being the documented exception, because deferring it is normal and what
 * it breaks (registration, password reset) is survivable and obvious.
 */
final class EnvironmentReport
{
    /** @var array<string, bool> */
    private array $results = [];

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $warnings = [];

    public function pass(string $check): void
    {
        $this->results[$check] = true;
    }

    /**
     * A failure that stops the install. Everything downstream assumes this
     * holds, so continuing produces a half-wired app that is harder to
     * diagnose than a refusal.
     */
    public function fail(string $check, string $message): void
    {
        $this->results[$check] = false;
        $this->failures[] = $message;
    }

    /**
     * A failure the operator can live with for now. Recorded, reported at the
     * end, never fatal.
     */
    public function warn(string $check, string $message): void
    {
        $this->results[$check] = false;
        $this->warnings[] = $message;
    }

    /**
     * Whether a check ran at all. Distinct from failing: Redis is not checked
     * on an install that never announces, and reporting that as a failure
     * would be wrong.
     */
    public function checked(string $check): bool
    {
        return array_key_exists($check, $this->results);
    }

    public function passed(string $check): bool
    {
        return $this->results[$check] ?? false;
    }

    public function isFatal(): bool
    {
        return $this->failures !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /** @return list<string> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
