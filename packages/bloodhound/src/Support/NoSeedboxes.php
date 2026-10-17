<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use Marque\Bloodhound\Contracts\SeedboxRegistry;

/**
 * No member has a registered seedbox: every announce is held to the ordinary cap.
 */
final class NoSeedboxes implements SeedboxRegistry
{
    public function isSeedbox(int $userId, string $ip): bool
    {
        return false;
    }
}
