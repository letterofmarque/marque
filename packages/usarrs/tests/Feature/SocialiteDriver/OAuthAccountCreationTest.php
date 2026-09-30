<?php

declare(strict_types=1);

// Spec #142 criterion 4. The callback used to create an account for any
// identity it didn't recognise — under every mode, invites or not — and sign it
// straight in, unverified. Now OAuth creates an account only where the
// registration rules allow one, exactly as /register applies them, and the new
// account is unverified and sent the verification email like anyone else.

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Tests\FakeOAuthProvider;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    $this->oauth = new FakeOAuthProvider;
    app()->instance(OAuthProvider::class, $this->oauth);
});

function newcomer(): ?TestUser
{
    return TestUser::where('email', 'newcomer@example.com')->first();
}

it('creates an account for a new identity when registration is open', function () {
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com', 'New Comer');

    $this->get(route('socialite.callback', 'github'))->assertRedirect('/');

    expect(newcomer())->not->toBeNull()
        ->and(newcomer()->name)->toBe('New Comer');
    $this->assertAuthenticatedAs(newcomer());
    expect(SocialAccount::resolve('github', 'gh-new')?->user_id)->toBe(newcomer()->getKey());
});

it('leaves the new account unverified and sends the verification email', function () {
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');

    $this->get(route('socialite.callback', 'github'));

    expect(newcomer()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo(newcomer(), VerifyEmail::class);
});

it('gives the new account no password anyone could know', function () {
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');

    $this->get(route('socialite.callback', 'github'));

    expect(newcomer()->password)->not->toBeEmpty()
        ->and(Hash::check('', newcomer()->password))->toBeFalse();
});

it('creates nothing when invites are required and none was given', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');

    $this->get(route('socialite.callback', 'github'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(newcomer())->toBeNull();
    $this->assertGuest();
});

it('creates the account and redeems the invite carried through the redirect', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);
    $inviter = TestUser::factory()->create();
    $invite = app(InviteServiceInterface::class)->create($inviter);

    $this->get(route('socialite.redirect', ['provider' => 'github', 'invite' => $invite->code]));
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');
    $this->get(route('socialite.callback', 'github'))->assertRedirect('/');

    expect(newcomer())->not->toBeNull()
        ->and($invite->fresh()->used_by_id)->toBe(newcomer()->getKey());
});

it('refuses an invite that is not valid', function () {
    config()->set('usarrs.invites.enabled', true);
    config()->set('usarrs.invites.required', true);

    $this->get(route('socialite.redirect', ['provider' => 'github', 'invite' => 'not-a-real-code']));
    $this->oauth->asserts('github', 'gh-new', 'newcomer@example.com');
    $this->get(route('socialite.callback', 'github'))->assertSessionHasErrors('email');

    expect(newcomer())->toBeNull();
});

it('creates nothing for an identity with no email address', function () {
    $this->oauth->asserts('github', 'gh-no-mail', null);

    $this->get(route('socialite.callback', 'github'))->assertSessionHasErrors('email');

    expect(TestUser::count())->toBe(0);
    $this->assertGuest();
});
