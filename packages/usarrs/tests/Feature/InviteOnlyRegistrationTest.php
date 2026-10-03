<?php

declare(strict_types=1);

// Issue #10801. Under auth_driver=invite_only, /register 404'd before it read
// the invite code, and it was the only place an invite was redeemed, so no
// account could be created by any route: the headline private-tracker shape
// was a dead end. Separately, an invite was only marked used when
// invites.required was on, so with invites merely enabled it was never
// consumed.

use Livewire\Livewire;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Enums\InviteStatus;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    config()->set('usarrs.invites.enabled', true);
    $this->invite = app(InviteServiceInterface::class)->create(TestUser::factory()->create());
});

function registerWith(string $invite): mixed
{
    return Livewire::withQueryParams(['invite' => $invite])->test(Register::class)
        ->set('name', 'Invited Person')
        ->set('email', 'invited@example.com')
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password')
        ->call('register');
}

function invitee(): ?TestUser
{
    return TestUser::where('email', 'invited@example.com')->first();
}

describe('under invite_only', function () {
    beforeEach(fn () => config()->set('usarrs.auth_driver', 'invite_only'));

    it('opens the registration form to someone holding a valid invite', function () {
        $this->get(route('register', ['invite' => $this->invite->code]))->assertOk();
    });

    it('creates the account and uses up the invite', function () {
        registerWith($this->invite->code)->assertHasNoErrors();

        expect(invitee())->not->toBeNull();
        $invite = Invite::find($this->invite->getKey());
        expect($invite->status)->toBe(InviteStatus::Used)
            ->and($invite->used_by_id)->toBe(invitee()->getKey());
    });

    it('404s without an invite, as before', function () {
        $this->get(route('register'))->assertNotFound();
    });

    it('404s with a code that is not an invite', function () {
        $this->get(route('register', ['invite' => 'not-a-real-code']))->assertNotFound();
    });

    it('404s with an invite that has already been used', function () {
        $this->invite->forceFill(['status' => InviteStatus::Used->value])->save();

        $this->get(route('register', ['invite' => $this->invite->code]))->assertNotFound();
    });

    it('404s with an invite that has expired', function () {
        $this->invite->forceFill(['expires_at' => now()->subDay()])->save();

        $this->get(route('register', ['invite' => $this->invite->code]))->assertNotFound();
    });

    it('creates nothing when the invite is swapped out of the form before submitting', function () {
        Livewire::withQueryParams(['invite' => $this->invite->code])->test(Register::class)
            ->set('invite', 'tampered')
            ->set('name', 'Invited Person')
            ->set('email', 'invited@example.com')
            ->set('password', 'a-long-password')
            ->set('password_confirmation', 'a-long-password')
            ->call('register')
            ->assertHasErrors('invite');

        expect(invitee())->toBeNull();
    });
});

describe('under the password driver', function () {
    it('uses up an invite that was presented, even when invites are not required', function () {
        config()->set('usarrs.invites.required', false);

        registerWith($this->invite->code)->assertHasNoErrors();

        expect(Invite::find($this->invite->getKey())->status)->toBe(InviteStatus::Used);
    });

    it('still registers without one when invites are not required', function () {
        config()->set('usarrs.invites.required', false);

        registerWith('')->assertHasNoErrors();

        expect(invitee())->not->toBeNull();
    });
});
