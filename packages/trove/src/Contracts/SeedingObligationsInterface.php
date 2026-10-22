<?php

declare(strict_types=1);

namespace Marque\Trove\Contracts;

use Marque\Trove\Support\SeedingObligation;

/**
 * A member's seeding obligations under the tracker's hit-and-run rule
 * (Job #256): the torrents they owe seeding on, and the ones they've been
 * struck for.
 *
 * Declared here and implemented by the tracker (bloodhound), the same split
 * as TrackerSessionsInterface: the rule is the tracker's, showing it is the
 * frontend's. Nothing bound means no rule: ask the container first.
 *
 *     if (app()->bound(SeedingObligationsInterface::class)) { ... }
 */
interface SeedingObligationsInterface
{
    /**
     * The member's unmet obligations and strikes: struck first, then
     * prewarned, then open, each by the most seeding owed. An empty list when
     * the rule is off or they owe nothing.
     *
     * @return list<SeedingObligation>
     */
    public function obligationsFor(UserInterface $user): array;
}
