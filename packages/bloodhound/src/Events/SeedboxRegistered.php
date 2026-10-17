<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Marque\Trove\Support\Seedbox;

/**
 * A member registered a seedbox. $by is null: the member is the seedbox's owner.
 */
class SeedboxRegistered
{
    use Dispatchable;

    public function __construct(
        public readonly Seedbox $seedbox,
        public readonly ?int $by = null,
    ) {}
}
