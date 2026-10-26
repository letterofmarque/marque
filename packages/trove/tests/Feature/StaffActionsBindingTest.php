<?php

declare(strict_types=1);

use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Support\NullStaffActions;
use Marque\Trove\Support\StaffAction;
use Marque\Trove\TroveServiceProvider;

/**
 * Staff actions are recorded wherever they happen, whether or not anything
 * stores them. A package records unconditionally; with nothing bound, the
 * record goes nowhere and nothing breaks.
 */
class RecordingStaffActions implements StaffActionsInterface
{
    /** @var list<StaffAction> */
    public array $recorded = [];

    public function record(StaffAction $action): void
    {
        $this->recorded[] = $action;
    }
}

function staffAction(): StaffAction
{
    return new StaffAction(actorId: 1, action: 'usarrs.user.banned', subjectType: 'user', subjectId: 2);
}

describe('with nothing bound', function () {
    it('resolves to a recorder that does nothing', function () {
        expect(app(StaffActionsInterface::class))->toBeInstanceOf(NullStaffActions::class);

        app(StaffActionsInterface::class)->record(staffAction());
    })->throwsNoExceptions();
});

describe('a package that stores staff actions', function () {
    it('receives every record once it binds the contract', function () {
        $store = new RecordingStaffActions;
        app()->instance(StaffActionsInterface::class, $store);

        $action = staffAction();
        app(StaffActionsInterface::class)->record($action);

        expect($store->recorded)->toBe([$action]);
    });

    it('keeps its binding when trove registers after it', function () {
        // Provider order isn't something a package controls. trove's default
        // must not replace a binding made before it.
        $store = new RecordingStaffActions;
        app()->instance(StaffActionsInterface::class, $store);

        (new TroveServiceProvider(app()))->register();

        expect(app(StaffActionsInterface::class))->toBe($store);
    });
});
