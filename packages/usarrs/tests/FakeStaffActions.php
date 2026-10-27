<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Support\StaffAction;

/**
 * A staff action store that keeps what it's given, for asserting what usarrs
 * records.
 */
class FakeStaffActions implements StaffActionsInterface
{
    /** @var list<StaffAction> */
    public array $recorded = [];

    public function record(StaffAction $action): void
    {
        $this->recorded[] = $action;
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map(fn (StaffAction $a): string => $a->action, $this->recorded);
    }
}
