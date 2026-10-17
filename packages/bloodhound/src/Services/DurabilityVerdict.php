<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

/**
 * What the Redis durability check found.
 */
final readonly class DurabilityVerdict
{
    /**
     * @param  list<string>  $problems  each says what is wrong and what to set
     * @param  list<string>  $warnings  worth fixing, not reason to refuse
     */
    public function __construct(
        public ?bool $aofEnabled,
        public ?string $evictionPolicy,
        public bool $attested,
        public array $problems,
        public array $warnings = [],
    ) {}

    public function ok(): bool
    {
        return $this->problems === [];
    }
}
