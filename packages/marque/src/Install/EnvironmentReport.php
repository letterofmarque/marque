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

    /** @var list<array{check: string, text: string, fatal: bool}> */
    private array $messages = [];

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
        $this->messages[] = ['check' => $check, 'text' => $message, 'fatal' => true];
    }

    /**
     * A failure the operator can live with for now. Recorded, reported at the
     * end, never fatal.
     */
    public function warn(string $check, string $message): void
    {
        $this->results[$check] = false;
        $this->messages[] = ['check' => $check, 'text' => $message, 'fatal' => false];
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
        return $this->failures() !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings() !== [];
    }

    /** @return list<string> */
    public function failures(): array
    {
        return $this->textOf(true);
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->textOf(false);
    }

    /**
     * Messages belonging to the named checks.
     *
     * The gate runs in two passes either side of the interview — the database
     * before, Redis after — so the second pass needs to render only its own
     * lines rather than reprinting what the operator has already read.
     *
     * @param  list<string>  $checks
     * @return list<array{check: string, text: string, fatal: bool}>
     */
    public function messagesFor(array $checks): array
    {
        return array_values(array_filter(
            $this->messages,
            fn (array $m): bool => in_array($m['check'], $checks, true),
        ));
    }

    /** @return list<string> */
    private function textOf(bool $fatal): array
    {
        return array_values(array_map(
            fn (array $m): string => $m['text'],
            array_filter($this->messages, fn (array $m): bool => $m['fatal'] === $fatal),
        ));
    }
}
