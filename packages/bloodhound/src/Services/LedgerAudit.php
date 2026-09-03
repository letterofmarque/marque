<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

/**
 * What the arithmetic audit found in the ledger itself.
 *
 * Distinct from reconciliation, which compares projections against the ledger.
 * This checks whether the ledger's own rows are internally coherent — a
 * question that only became askable once rows carried the baseline they were
 * diffed against.
 *
 * See Spec #99.
 */
final class LedgerAudit
{
    /**
     * Rows where delta does not equal reported minus prior.
     *
     * @param  array<int, array<string, mixed>>  $arithmeticBreaks
     *
     * Rows whose prior does not match the previous row's reported value for
     * the same (torrent, peer). A break means a baseline went missing between
     * two announces — the signature of a Redis loss, visible after the fact
     * rather than silent.
     * @param  array<int, array<string, mixed>>  $chainBreaks
     */
    public function __construct(
        public readonly array $arithmeticBreaks = [],
        public readonly array $chainBreaks = [],
    ) {}

    public function hasBreaks(): bool
    {
        return $this->arithmeticBreaks !== [] || $this->chainBreaks !== [];
    }
}
