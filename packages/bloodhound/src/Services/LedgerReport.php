<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

/**
 * What reconciliation found.
 *
 * `pending` is deliberately separate from drift: rows the aggregator has not
 * consumed yet are a backlog, and reporting a backlog as drift would make the
 * alerting noisy — which is how a detection mechanism ends up ignored.
 *
 * See Spec #99.
 */
final class LedgerReport
{
    /**
     * @param  array<int, array{user_id: int, column: string, recorded: int, expected: int}>  $userDrift
     * @param  array<int, array{user_id: int, torrent_id: int, column: string, recorded: int, expected: int}>  $torrentUserDrift
     */
    public function __construct(
        public readonly array $userDrift = [],
        public readonly array $torrentUserDrift = [],
        public readonly int $pending = 0,
    ) {}

    public function hasDrift(): bool
    {
        return $this->userDrift !== [] || $this->torrentUserDrift !== [];
    }
}
