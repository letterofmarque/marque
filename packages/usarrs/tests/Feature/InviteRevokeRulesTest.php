<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Invite\InviteIndex;
use Marque\Usarrs\Services\InviteService;
use Marque\Usarrs\Tests\TestUser;

/**
 * Who may revoke whose invite (Dan, 2026-10-27): its creator, or anyone who
 * could manage the creator. So admins revoke the invites of users, uploaders
 * and moderators, and only an owner revokes an admin's or an owner's.
 */
beforeEach(function () {
    config(['usarrs.invites.enabled' => true]);

    $this->service = app(InviteService::class);
    $this->owner = TestUser::factory()->owner()->create();
    $this->admin = TestUser::factory()->admin()->create();
    $this->member = TestUser::factory()->create();
});

it('lets the creator revoke their own', function () {
    $invite = $this->service->create($this->member);

    $this->service->revoke($invite, actor: $this->member);

    expect($invite->fresh()->status->value)->toBe('revoked');
});

it('lets an admin revoke a member\'s', function () {
    $invite = $this->service->create($this->member);

    $this->service->revoke($invite, actor: $this->admin);

    expect($invite->fresh()->status->value)->toBe('revoked');
});

it('refuses an admin revoking another admin\'s, or the owner\'s', function () {
    foreach ([$this->owner, TestUser::factory()->admin()->create()] as $creator) {
        $invite = $this->service->create($creator);

        expect(fn () => $this->service->revoke($invite, actor: $this->admin))->toThrow(AuthorizationException::class)
            ->and($invite->fresh()->status->value)->toBe('pending');
    }
});

it('lets the owner revoke an admin\'s', function () {
    $invite = $this->service->create($this->admin);

    $this->service->revoke($invite, actor: $this->owner);

    expect($invite->fresh()->status->value)->toBe('revoked');
});

it('refuses a member revoking someone else\'s', function () {
    $invite = $this->service->create(TestUser::factory()->create());

    $this->service->revoke($invite, actor: $this->member);
})->throws(AuthorizationException::class);

it('guards the invites screen with the same rule', function () {
    $invite = $this->service->create($this->owner);

    Livewire::actingAs($this->admin)
        ->test(InviteIndex::class)
        ->call('revoke', $invite->id)
        ->assertForbidden();

    expect($invite->fresh()->status->value)->toBe('pending');
});
