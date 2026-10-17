<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Marque\Trove\Support\Seedbox;

/**
 * An admin revoked a seedbox's approval. $by is the admin's user id.
 */
class SeedboxRevoked
{
    use Dispatchable;

    public function __construct(
        public readonly Seedbox $seedbox,
        public readonly ?int $by = null,
    ) {}
}
