<?php

declare(strict_types=1);

use Marque\Bloodhound\Models\MemberStanding;
use Marque\Bloodhound\Services\RatioEnforcer;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Trove\Contracts\SeedboxesInterface;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Support\StaffAction;

/**
 * The record is made inside the change's own transaction, so the two stand or
 * fall together: a change rolled back is never recorded, and a store that
 * fails closed (throws, on purpose) takes the change down with it. Shown here
 * with a store that refuses.
 */
it('rolls the change back when the store refuses the record', function () {
    $admin = TestUser::create(['name' => 'A', 'email' => 'tx-admin@example.com', 'password' => 'p', 'role' => 'admin']);
    $member = TestUser::create(['name' => 'M', 'email' => 'tx-member@example.com', 'password' => 'p']);
    $seedbox = app(SeedboxesInterface::class)->register($member, '203.0.113.9');

    app()->instance(StaffActionsInterface::class, new class implements StaffActionsInterface
    {
        public function record(StaffAction $action): void
        {
            throw new RuntimeException('store refused');
        }
    });

    expect(fn () => app(RatioEnforcer::class)->exempt($member->id, by: $admin->id, reason: 'Donor'))->toThrow(RuntimeException::class, 'store refused')
        ->and(fn () => app(SeedboxesInterface::class)->approve($seedbox->id, $admin))->toThrow(RuntimeException::class, 'store refused');

    expect(MemberStanding::query()->where('user_id', $member->id)->exists())->toBeFalse()
        ->and(app(SeedboxesInterface::class)->forUser($member)[0]->status)->toBe('pending');
});
