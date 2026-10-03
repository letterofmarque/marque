<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Marque\Usarrs\Enums\InviteStatus;
use Marque\Usarrs\Exceptions\InviteAlreadyRedeemed;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Notifications\InviteNotification;
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

test('emails the invite to the recipient, not to the member who created it (found in Job #141)', function () {
    Notification::fake();

    $invite = $this->service->create($this->user, 'recipient@example.com');

    Notification::assertSentOnDemand(InviteNotification::class, function (InviteNotification $n, array $channels, object $notifiable) use ($invite) {
        return $notifiable->routes['mail'] === 'recipient@example.com' && $n->invite->is($invite);
    });
    Notification::assertNotSentTo($this->user, InviteNotification::class);
});

test('sends nothing when the invite has no recipient', function () {
    Notification::fake();

    $this->service->create($this->user);

    Notification::assertNothingSent();
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

// Build #124 CP #763. Redeeming used to write "used" over whatever was there,
// so two registrations racing on one invite both got it.
test('refuses to redeem an invite someone else redeemed first', function () {
    $invite = $this->service->create($this->user);
    $first = TestUser::factory()->create();
    $second = TestUser::factory()->create();
    $stale = Invite::find($invite->id); // loaded while still pending

    $this->service->redeem($invite, $first);

    expect(fn () => $this->service->redeem($stale, $second))->toThrow(InviteAlreadyRedeemed::class)
        ->and($invite->fresh()->used_by_id)->toBe($first->id);
});

test('refuses to redeem an expired or revoked invite', function () {
    $expired = $this->service->create($this->user);
    $expired->update(['expires_at' => now()->subMinute()]);
    $revoked = $this->service->create($this->user);
    $this->service->revoke($revoked);
    $newUser = TestUser::factory()->create();

    expect(fn () => $this->service->redeem($expired, $newUser))->toThrow(InviteAlreadyRedeemed::class)
        ->and(fn () => $this->service->redeem($revoked, $newUser))->toThrow(InviteAlreadyRedeemed::class);
});
