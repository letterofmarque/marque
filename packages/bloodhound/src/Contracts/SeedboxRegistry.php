<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Contracts;

/**
 * Whether an announce comes from one of the member's approved seedboxes.
 *
 * A seedbox is measured against its own, higher, speed cap; everyone else
 * against the ordinary one, which an operator can then set to what their
 * members' home connections actually do.
 */
interface SeedboxRegistry
{
    public function isSeedbox(int $userId, string $ip): bool;
}
