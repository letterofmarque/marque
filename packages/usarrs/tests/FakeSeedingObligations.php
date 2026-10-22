<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Marque\Trove\Contracts\SeedingObligationsInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Support\SeedingObligation;

/**
 * A tracker's hit-and-run obligations, as usarrs sees them: whatever the test
 * puts in $list.
 */
class FakeSeedingObligations implements SeedingObligationsInterface
{
    /** @var list<SeedingObligation> */
    public array $list = [];

    public function obligationsFor(UserInterface $user): array
    {
        return $this->list;
    }
}
