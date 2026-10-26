<?php

declare(strict_types=1);

namespace Marque\Trove\Support;

use Marque\Trove\Contracts\StaffActionsInterface;

/**
 * What StaffActionsInterface resolves to when nothing stores staff actions.
 *
 * Packages record unconditionally, so this keeps every one of them from asking
 * whether a store is installed.
 */
final class NullStaffActions implements StaffActionsInterface
{
    public function record(StaffAction $action): void
    {
        //
    }
}
