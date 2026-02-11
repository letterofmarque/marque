<?php

declare(strict_types=1);

use Marque\Usarrs\Enums\InviteStatus;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Services\InviteService;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    $this->service = new InviteService;
    config()->set('usarrs.invites.enabled', true);
});

test('can create an invite', function () {
    $invite = $this->service->create($this->user);

    expect($invite)->toBeInstanceOf(Invite::class)
        ->and($invite->code)->toHaveLength(32)
        ->and($invite->creator_id)->toBe($this->user->id)
        ->and($invite->status)->toBe(InviteStatus::Pending)
        ->and($invite->expires_at)->not->toBeNull();
});

test('can create invite with recipient email', function () {
    $invite = $this->service->create($this->user, 'recipient@example.com');

    expect($invite->recipient_email)->toBe('recipient@example.com');
});

test('can redeem an invite', function () {
    $invite = $this->service->create($this->user);
    $newUser = TestUser::factory()->create();

    $this->service->redeem($invite, $newUser);

    $invite->refresh();
    expect($invite->status)->toBe(InviteStatus::Used)
        ->and($invite->used_by_id)->toBe($newUser->id);
});

test('can revoke an invite', function () {
    $invite = $this->service->create($this->user);

    $this->service->revoke($invite);

    $invite->refresh();
    expect($invite->status)->toBe(InviteStatus::Revoked);
});

test('can find invite by code', function () {
    $invite = $this->service->create($this->user);

    $found = $this->service->findByCode($invite->code);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($invite->id);
});

test('returns null for unknown code', function () {
    expect($this->service->findByCode('nonexistent'))->toBeNull();
});

test('respects max invites per user', function () {
    config()->set('usarrs.invites.max_per_user', 2);

    $this->service->create($this->user);
    $this->service->create($this->user);

    expect($this->service->canCreateInvite($this->user))->toBeFalse();
});

test('can create invite after previous ones are used', function () {
    config()->set('usarrs.invites.max_per_user', 1);

    $invite = $this->service->create($this->user);
    $newUser = TestUser::factory()->create();
    $this->service->redeem($invite, $newUser);

    expect($this->service->canCreateInvite($this->user))->toBeTrue();
});
