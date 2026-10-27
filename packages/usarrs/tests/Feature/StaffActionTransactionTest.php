<?php

declare(strict_types=1);

use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Enums\Role;
use Marque\Trove\Support\StaffAction;
use Marque\Usarrs\Services\UserAdminService;
use Marque\Usarrs\Tests\TestUser;

/**
 * The record is made inside the change's own transaction, so the two stand or
 * fall together: a change rolled back is never recorded, and a store that
 * fails closed (throws, on purpose) takes the change down with it. Shown here
 * with a store that refuses.
 */
it('rolls the change back when the store refuses the record', function () {
    app()->instance(StaffActionsInterface::class, new class implements StaffActionsInterface
    {
        public function record(StaffAction $action): void
        {
            throw new RuntimeException('store refused');
        }
    });

    $owner = TestUser::factory()->owner()->create();
    $member = TestUser::factory()->create();

    expect(fn () => app(UserAdminService::class)->setRole($member, Role::Moderator, actor: $owner))->toThrow(RuntimeException::class, 'store refused')
        ->and(fn () => app(UserAdminService::class)->ban($member, actor: $owner))->toThrow(RuntimeException::class, 'store refused');

    expect($member->fresh()->role)->toBe(Role::User)
        ->and($member->fresh()->status)->toBe('active');
});
