<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Enums\Role;
use Marque\Usarrs\Services\InviteService;
use Marque\Usarrs\Services\UserAdminService;
use Marque\Usarrs\Tests\FakeStaffActions;
use Marque\Usarrs\Tests\TestUser;

/**
 * What usarrs records through trove's StaffActionsInterface (Plan #16): what
 * one person does to another's account or invite. Nothing is recorded without
 * an actor, because nobody acted.
 */
beforeEach(function () {
    config(['usarrs.invites.enabled' => true]);

    $this->store = new FakeStaffActions;
    app()->instance(StaffActionsInterface::class, $this->store);

    $this->owner = TestUser::factory()->owner()->create();
    $this->admin = TestUser::factory()->admin()->create();
    $this->member = TestUser::factory()->create();
});

describe('accounts', function () {
    it('records a ban and an unban, with who, whom, why and what moved', function () {
        $service = app(UserAdminService::class);

        $service->ban($this->member, actor: $this->admin, reason: 'Ratio cheating');
        $service->unban($this->member, actor: $this->admin);

        [$ban, $unban] = $this->store->recorded;

        expect($ban->action)->toBe('usarrs.user.banned')
            ->and($ban->actorId)->toBe($this->admin->id)
            ->and($ban->subjectType)->toBe($this->member->getMorphClass())
            ->and($ban->subjectId)->toBe($this->member->id)
            ->and($ban->reason)->toBe('Ratio cheating')
            ->and($ban->changes)->toBe(['status' => ['from' => 'active', 'to' => 'banned']])
            ->and($unban->action)->toBe('usarrs.user.unbanned')
            ->and($unban->changes)->toBe(['status' => ['from' => 'banned', 'to' => 'active']]);
    });

    it('records a role change with the role before and after', function () {
        app(UserAdminService::class)->setRole($this->member, Role::Moderator, actor: $this->owner, reason: 'Good reports');

        expect($this->store->recorded)->toHaveCount(1)
            ->and($this->store->recorded[0]->action)->toBe('usarrs.user.role_changed')
            ->and($this->store->recorded[0]->reason)->toBe('Good reports')
            ->and($this->store->recorded[0]->changes)->toBe(['role' => ['from' => 'user', 'to' => 'moderator']]);
    });

    it('records nothing when no one acted', function () {
        $service = app(UserAdminService::class);

        $service->ban($this->member);
        $service->setRole($this->member, Role::Uploader);

        expect($this->store->recorded)->toBe([]);
    });

    it('records nothing for a change that was refused', function () {
        expect(fn () => app(UserAdminService::class)->setRole($this->owner, Role::User, actor: $this->admin))
            ->toThrow(AuthorizationException::class);

        expect($this->store->recorded)->toBe([]);
    });
});

describe('invites', function () {
    it('records revoking someone else\'s invite', function () {
        $invite = app(InviteService::class)->create($this->member);

        app(InviteService::class)->revoke($invite, actor: $this->admin, reason: 'Selling invites');

        expect($this->store->actions())->toBe(['usarrs.invite.revoked'])
            ->and($this->store->recorded[0]->subjectId)->toBe($invite->id)
            ->and($this->store->recorded[0]->reason)->toBe('Selling invites')
            ->and($this->store->recorded[0]->changes)->toBe(['status' => ['from' => 'pending', 'to' => 'revoked']]);
    });

    it('records nothing when the creator revokes their own', function () {
        $invite = app(InviteService::class)->create($this->member);

        app(InviteService::class)->revoke($invite, actor: $this->member);

        expect($invite->fresh()->status->value)->toBe('revoked')
            ->and($this->store->recorded)->toBe([]);
    });
});
