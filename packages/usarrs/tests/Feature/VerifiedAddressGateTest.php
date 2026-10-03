<?php

declare(strict_types=1);

// Issue #10879. profile, security and invite routes sat behind `auth`, not
// `verified`, so an account nobody had proven the address of could attach
// sign-in methods and mint invites. Dan, 2026-10-01: tracker admins want
// verified addresses, so that is the default. Connecting an OAuth provider
// was already gated (CP #776, OAuthSignedInLinkingTest); passkeys are in
// PasskeysEnabled/, announce keys in TrackerBound/.

use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Invite\InviteCreate;
use Marque\Usarrs\Livewire\Profile\TwoFactorSetup;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Notifications\InviteNotification;
use Marque\Usarrs\Tests\TestUser;

describe('two-factor authentication', function () {
    beforeEach(fn () => config()->set('usarrs.two_factor.enabled', true));

    it('cannot be turned on from an unverified account', function () {
        $user = TestUser::factory()->unverified()->create();

        Livewire::actingAs($user)->test(TwoFactorSetup::class)
            ->call('enable')
            ->assertHasErrors('email')
            ->assertSee(__('Verify your email address before turning on two-factor authentication.'));

        expect($user->fresh()->two_factor_secret)->toBeNull();
    });

    it('can be turned on from a verified one', function () {
        $user = TestUser::factory()->create();

        Livewire::actingAs($user)->test(TwoFactorSetup::class)->call('enable');

        expect($user->fresh()->two_factor_secret)->not->toBeNull();
    });
});

describe('invites', function () {
    beforeEach(fn () => config()->set('usarrs.invites.enabled', true));

    it('cannot be created once the address is unverified, even from a form opened before', function () {
        // Changing your email un-verifies it (CP #776), so a form can be open
        // on an account that has just stopped being proven.
        $user = TestUser::factory()->create();
        $form = Livewire::actingAs($user)->test(InviteCreate::class);

        $user->forceFill(['email_verified_at' => null])->save();

        $form->call('create')
            ->assertHasErrors('email')
            ->assertSee(__('Verify your email address before creating invites.'));

        expect(Invite::count())->toBe(0);
    });

    it('sends an unverified account from the invite form to verify first', function () {
        $this->actingAs(TestUser::factory()->unverified()->create())
            ->get(route('invites.create'))
            ->assertRedirect(route('verification.notice'));
    });

    it('can be created from a verified one', function () {
        Livewire::actingAs(TestUser::factory()->create())->test(InviteCreate::class)->call('create');

        expect(Invite::count())->toBe(1);
    });
});

describe('invite email', function () {
    // Job #141 review: invites now reach the address typed in, and creating,
    // revoking and creating again sent as many as anyone liked.
    it('stops sending invite emails past ten an hour for one member', function () {
        config()->set('usarrs.invites.enabled', true);
        config()->set('usarrs.invites.max_per_user', 100);
        Notification::fake();
        $user = TestUser::factory()->create();

        foreach (range(1, 10) as $n) {
            Livewire::actingAs($user)->test(InviteCreate::class)
                ->set('recipientEmail', "friend{$n}@example.com")
                ->call('create')
                ->assertHasNoErrors();
        }

        Livewire::actingAs($user)->test(InviteCreate::class)
            ->set('recipientEmail', 'one-more@example.com')
            ->call('create')
            ->assertHasErrors('recipientEmail');

        expect(Invite::count())->toBe(10);
        Notification::assertSentOnDemandTimes(InviteNotification::class, 10);
    });
});
