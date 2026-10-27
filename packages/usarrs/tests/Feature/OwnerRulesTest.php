<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Marque\Trove\Enums\Role;
use Marque\Usarrs\Services\UserAdminService;
use Marque\Usarrs\Tests\TestUser;

/**
 * Who may change whose role, or ban whom (trove 5's Owner, Plan #16).
 *
 * Before the Owner role, any admin could demote or ban any other admin, the
 * tracker's owner included. Now admins and owners are managed by an owner
 * alone, granting Admin or Owner is the owner's alone, nobody acts on
 * themselves, and the last owner can't be demoted.
 */
beforeEach(function () {
    $this->service = app(UserAdminService::class);
    $this->owner = TestUser::factory()->owner()->create();
    $this->admin = TestUser::factory()->admin()->create();
    $this->moderator = TestUser::factory()->moderator()->create();
    $this->member = TestUser::factory()->create();
});

describe('an admin', function () {
    it('promotes and demotes members and moderators', function () {
        $this->service->setRole($this->member, Role::Moderator, actor: $this->admin);
        $this->service->setRole($this->moderator, Role::Uploader, actor: $this->admin);

        expect($this->member->fresh()->role)->toBe(Role::Moderator)
            ->and($this->moderator->fresh()->role)->toBe(Role::Uploader);
    });

    it('cannot grant Admin or Owner', function (Role $role) {
        $this->service->setRole($this->member, $role, actor: $this->admin);
    })->with([Role::Admin, Role::Owner])->throws(AuthorizationException::class);

    it('cannot change another admin\'s role, or the owner\'s', function () {
        $other = TestUser::factory()->admin()->create();

        expect(fn () => $this->service->setRole($other, Role::User, actor: $this->admin))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->setRole($this->owner, Role::User, actor: $this->admin))->toThrow(AuthorizationException::class)
            ->and($other->fresh()->role)->toBe(Role::Admin)
            ->and($this->owner->fresh()->role)->toBe(Role::Owner);
    });

    it('cannot ban or unban another admin, or the owner', function () {
        $other = TestUser::factory()->admin()->create();

        expect(fn () => $this->service->ban($other, actor: $this->admin))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->ban($this->owner, actor: $this->admin))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->unban(TestUser::factory()->admin()->banned()->create(), actor: $this->admin))->toThrow(AuthorizationException::class)
            ->and($other->fresh()->status)->toBe('active')
            ->and($this->owner->fresh()->status)->toBe('active');
    });

    it('bans a member', function () {
        $this->service->ban($this->member, actor: $this->admin);

        expect($this->member->fresh()->status)->toBe('banned');
    });
});

describe('the owner', function () {
    it('grants and removes Admin', function () {
        $this->service->setRole($this->member, Role::Admin, actor: $this->owner);
        $this->service->setRole($this->admin, Role::Moderator, actor: $this->owner);

        expect($this->member->fresh()->role)->toBe(Role::Admin)
            ->and($this->admin->fresh()->role)->toBe(Role::Moderator);
    });

    it('makes another owner, and demotes one while another remains', function () {
        $this->service->setRole($this->admin, Role::Owner, actor: $this->owner);
        $this->service->setRole($this->admin, Role::Admin, actor: $this->owner);

        expect($this->admin->fresh()->role)->toBe(Role::Admin);
    });

    it('bans an admin', function () {
        $this->service->ban($this->admin, actor: $this->owner);

        expect($this->admin->fresh()->status)->toBe('banned');
    });
});

describe('nobody', function () {
    it('changes their own role', function (string $who, Role $to) {
        $this->service->setRole($this->{$who}, $to, actor: $this->{$who});
    })->with([
        'owner stepping down' => ['owner', Role::Admin],
        'admin stepping down' => ['admin', Role::Moderator],
        'moderator stepping up' => ['moderator', Role::Admin],
    ])->throws(AuthorizationException::class);

    it('bans themselves', function () {
        $this->service->ban($this->owner, actor: $this->owner);
    })->throws(AuthorizationException::class);

    it('below admin changes anyone\'s role', function () {
        $this->service->setRole($this->member, Role::Uploader, actor: $this->moderator);
    })->throws(AuthorizationException::class);
});

describe('the last owner', function () {
    it('cannot be demoted, even with no actor', function () {
        // App code and the console pass no actor and skip the actor rules;
        // this one holds for everyone, or a tracker can be left with nobody
        // able to grant Admin.
        expect(fn () => $this->service->setRole($this->owner, Role::Admin))
            ->toThrow(AuthorizationException::class, 'last owner');

        expect($this->owner->fresh()->role)->toBe(Role::Owner);
    });

    it('can be demoted once there is another', function () {
        TestUser::factory()->owner()->create();

        $this->service->setRole($this->owner, Role::Admin);

        expect($this->owner->fresh()->role)->toBe(Role::Admin);
    });
});

describe('with no actor', function () {
    it('applies the change, as app code and the console do', function () {
        $this->service->setRole($this->member, Role::Admin);
        $this->service->ban($this->admin);

        expect($this->member->fresh()->role)->toBe(Role::Admin)
            ->and($this->admin->fresh()->status)->toBe('banned');
    });
});
