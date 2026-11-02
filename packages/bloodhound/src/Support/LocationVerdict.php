<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

/**
 * A LocationJudge's answer: pass, refuse, or ambiguous, with why.
 */
final readonly class LocationVerdict
{
    public const PASS = 'pass';

    public const REFUSE = 'refuse';

    public const AMBIGUOUS = 'ambiguous';

    private function __construct(
        public string $kind,
        public ?string $reason,
    ) {}

    public static function pass(): self
    {
        return new self(self::PASS, null);
    }

    public static function refuse(string $reason): self
    {
        return new self(self::REFUSE, $reason);
    }

    /**
     * A refusal that may be one machine on two address families. $reason is
     * the refusal it would be under `refuse`.
     */
    public static function ambiguous(string $reason): self
    {
        return new self(self::AMBIGUOUS, $reason);
    }

    public function passed(): bool
    {
        return $this->kind === self::PASS;
    }
}
