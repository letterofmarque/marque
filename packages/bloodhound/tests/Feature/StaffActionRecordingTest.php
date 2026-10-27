<?php

declare(strict_types=1);

use Marque\Bloodhound\Models\MemberStanding;
use Marque\Bloodhound\Services\RatioEnforcer;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Contracts\SeedboxesInterface;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Enums\StandingState;
use Marque\Trove\Support\StaffAction;

/**
 * What bloodhound records through trove's StaffActionsInterface (Plan #16):
 * exempting a member from the ratio rules and back, and approving or revoking
 * a seedbox. These are staff decisions, so they're recorded whoever they're
 * about, an admin's own account included: exempting yourself is exactly what
 * an audit is for.
 */
beforeEach(function () {
    $this->store = new class implements StaffActionsInterface
    {
        /** @var list<StaffAction> */
        public array $recorded = [];

        public function record(StaffAction $action): void
        {
            $this->recorded[] = $action;
        }
    };
    app()->instance(StaffActionsInterface::class, $this->store);

    $this->admin = TestUser::create(['name' => 'Admin', 'email' => 'staff-admin@example.com', 'password' => 'p', 'role' => 'admin']);
    $this->member = TestUser::create(['name' => 'Member', 'email' => 'staff-member@example.com', 'password' => 'p']);
});

describe('exemptions', function () {
    it('records an exemption granted and removed, with who, whom and why', function () {
        $enforcer = app(RatioEnforcer::class);

        $enforcer->exempt($this->member->id, by: $this->admin->id, reason: 'Donor');
        $enforcer->unexempt($this->member->id, by: $this->admin->id, reason: 'Donation lapsed');

        [$granted, $removed] = $this->store->recorded;

        expect($granted->action)->toBe('bloodhound.exemption.granted')
            ->and($granted->actorId)->toBe($this->admin->id)
            ->and($granted->subjectType)->toBe($this->member->getMorphClass())
            ->and($granted->subjectId)->toBe($this->member->id)
            ->and($granted->reason)->toBe('Donor')
            ->and($granted->changes)->toBe(['exempt' => ['from' => false, 'to' => true]])
            ->and($removed->action)->toBe('bloodhound.exemption.removed')
            ->and($removed->reason)->toBe('Donation lapsed')
            ->and($removed->changes)->toBe(['exempt' => ['from' => true, 'to' => false]]);
    });

    it('records an admin exempting themselves', function () {
        app(RatioEnforcer::class)->exempt($this->admin->id, by: $this->admin->id, reason: 'Testing');

        expect($this->store->recorded)->toHaveCount(1);
    });

    it('records nothing with no admin named, or when there was no exemption to remove', function () {
        $enforcer = app(RatioEnforcer::class);

        $enforcer->exempt($this->member->id, by: null, reason: 'Imported');

        // On watch but never exempted: a standing row, and nothing to remove.
        $watched = TestUser::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'p']);
        MemberStanding::query()->forceCreate(['user_id' => $watched->id, 'state' => StandingState::Watch->value, 'watch_started_at' => now()]);
        $enforcer->unexempt($watched->id, by: $this->admin->id);

        expect($this->store->recorded)->toBe([])
            ->and(MemberStanding::query()->where('user_id', $watched->id)->value('state'))->toBe(StandingState::Watch->value);
    });

    it('records through bloodhound:standing, which requires --by from bloodhound 10', function () {
        $this->artisan('bloodhound:standing', ['action' => 'exempt', 'user' => $this->member->id, '--reason' => 'Donor', '--by' => $this->admin->id])
            ->assertSuccessful();
        $this->artisan('bloodhound:standing', ['action' => 'unexempt', 'user' => $this->member->id, '--by' => $this->admin->id])
            ->assertSuccessful();

        expect(array_map(fn (StaffAction $a): string => $a->action, $this->store->recorded))
            ->toBe(['bloodhound.exemption.granted', 'bloodhound.exemption.removed']);
    });
});

describe('seedboxes', function () {
    it('records an approval and a revocation, with the status before and after', function () {
        $seedboxes = app(SeedboxesInterface::class);
        $seedbox = $seedboxes->register($this->member, '203.0.113.7');

        $seedboxes->approve($seedbox->id, $this->admin);
        $seedboxes->revoke($seedbox->id, $this->admin);

        [$approved, $revoked] = $this->store->recorded;

        expect($approved->action)->toBe('bloodhound.seedbox.approved')
            ->and($approved->actorId)->toBe($this->admin->id)
            ->and($approved->subjectId)->toBe($seedbox->id)
            ->and($approved->changes)->toBe(['status' => ['from' => 'pending', 'to' => 'approved']])
            ->and($revoked->action)->toBe('bloodhound.seedbox.revoked')
            ->and($revoked->changes)->toBe(['status' => ['from' => 'approved', 'to' => 'revoked']]);
    });

    it('records to a store bound after the seedbox service was first used', function () {
        // The service is a singleton. A store installed later (or bound by a
        // provider that boots after first use) must still get the record.
        $seedboxes = app(SeedboxesInterface::class);
        $seedbox = $seedboxes->register($this->member, '203.0.113.8');
        $seedboxes->approve($seedbox->id, $this->admin);

        $late = clone $this->store;
        $late->recorded = [];
        app()->instance(StaffActionsInterface::class, $late);

        $seedboxes->revoke($seedbox->id, $this->admin);

        expect($late->recorded)->toHaveCount(1)
            ->and($late->recorded[0]->action)->toBe('bloodhound.seedbox.revoked');
    });
});
