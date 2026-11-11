<?php

declare(strict_types=1);

// Job #355 step 2 (#10858 items 1 and 2). Connecting a provider while signed in
// needed no step-up and told nobody: a stolen session could connect the thief's
// provider and keep a way in after the owner took the account back. And
// nothing listed or removed a connection.
//
// Now a connection starts from the profile (POST socialite.connect), only with
// a confirmed step-up; only that round trip's callback connects anything; the
// member is mailed when one is connected or removed; and the profile lists
// them, with disconnect behind the same step-up, never removing the last way
// in.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Livewire\Profile\ConnectedAccounts;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Notifications\OAuthConnectionNotice;
use Marque\Usarrs\Tests\FakeOAuthProvider;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    config()->set('usarrs.socialite_providers', ['github', 'gitlab']);

    $this->oauth = new FakeOAuthProvider;
    app()->instance(OAuthProvider::class, $this->oauth);

    $this->me = TestUser::factory()->create(['email' => 'me@example.com']);
    SocialAccount::forceCreate(['user_id' => $this->me->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-me']);
});

function confirmedAs(object $test, TestUser $user): object
{
    return $test->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
}

// Starting a connection

it('sends an unconfirmed member to confirm it\'s them, then back to the profile', function () {
    $this->actingAs($this->me)->post(route('socialite.connect', 'gitlab'))
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route('profile.show'))
        ->and(session()->has('usarrs.oauth.intent'))->toBeFalse();
});

it('starts the round trip for a confirmed member', function () {
    confirmedAs($this, $this->me)->post(route('socialite.connect', 'gitlab'))
        ->assertRedirect('https://oauth.example/gitlab/authorize');

    expect(session('usarrs.oauth.intent'))->toBe('connect');
});

it('starts a connection only by POST, and only signed in', function () {
    $this->actingAs($this->me)->get(url('auth/gitlab/connect'))->assertMethodNotAllowed();
    auth()->logout();
    $this->post(route('socialite.connect', 'gitlab'))->assertRedirect(route('login'));
});

// The callback

it('connects nothing for a signed-in member who didn\'t start a connection', function () {
    // The sign-in button's GET round trip, or one a page started behind the
    // member's back: no step-up, so no connection.
    $this->oauth->asserts('gitlab', 'gl-new', 'me@example.com');

    $this->actingAs($this->me)->get(route('socialite.callback', 'gitlab'))
        ->assertRedirect(route('profile.show'))
        ->assertSessionHasErrors('email');

    expect(SocialAccount::resolve('gitlab', 'gl-new'))->toBeNull();
    $this->assertAuthenticatedAs($this->me);
});

it('connects nothing when the confirmation lapsed during the round trip', function () {
    $this->actingAs($this->me)->withSession([
        'usarrs.oauth.intent' => 'connect',
        'auth.password_confirmed_at' => time() - (int) config('auth.password_timeout', 10800) - 1,
    ]);
    $this->oauth->asserts('gitlab', 'gl-new', 'me@example.com');

    $this->get(route('socialite.callback', 'gitlab'))->assertRedirect(route('password.confirm'));

    expect(SocialAccount::resolve('gitlab', 'gl-new'))->toBeNull();
});

it('mails the member when a provider is connected', function () {
    confirmedAs($this, $this->me)->post(route('socialite.connect', 'gitlab'));
    $this->oauth->asserts('gitlab', 'gl-new', 'me@example.com', nickname: 'me-on-gitlab');

    $this->get(route('socialite.callback', 'gitlab'))->assertSessionHasNoErrors();

    expect(SocialAccount::resolve('gitlab', 'gl-new')?->user_id)->toBe($this->me->getKey());
    Notification::assertSentTo($this->me, OAuthConnectionNotice::class, fn ($n) => $n->provider === 'gitlab' && $n->connected === true);
});

it('mails nothing when nothing was connected', function () {
    confirmedAs($this, $this->me)->post(route('socialite.connect', 'github'));
    $this->oauth->asserts('github', 'gh-me', 'me@example.com');

    $this->get(route('socialite.callback', 'github'));

    Notification::assertNotSentTo($this->me, OAuthConnectionNotice::class);
});

// The profile's connected accounts

it('lists the member\'s connections and offers the providers they haven\'t connected', function () {
    Livewire::actingAs($this->me)->test(ConnectedAccounts::class)
        ->assertSee('Github')
        ->assertSee(route('socialite.connect', 'gitlab'))
        ->assertDontSee(route('socialite.connect', 'github'));
});

it('is on the profile page under socialite', function () {
    $this->actingAs($this->me)->get(route('profile.show'))
        ->assertOk()
        ->assertSeeLivewire(ConnectedAccounts::class);
});

it('sends an unconfirmed member to confirm before disconnecting, and back', function () {
    SocialAccount::forceCreate(['user_id' => $this->me->getKey(), 'provider' => 'gitlab', 'provider_user_id' => 'gl-me']);

    Livewire::actingAs($this->me)->test(ConnectedAccounts::class)
        ->call('disconnect', 'gitlab')
        ->assertRedirect(route('password.confirm'));

    expect(SocialAccount::resolve('gitlab', 'gl-me'))->not->toBeNull();
});

it('disconnects for a confirmed member, and mails them', function () {
    SocialAccount::forceCreate(['user_id' => $this->me->getKey(), 'provider' => 'gitlab', 'provider_user_id' => 'gl-me']);
    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->me)->test(ConnectedAccounts::class)
        ->call('disconnect', 'gitlab')
        ->assertHasNoErrors();

    expect(SocialAccount::resolve('gitlab', 'gl-me'))->toBeNull()
        ->and(SocialAccount::resolve('github', 'gh-me'))->not->toBeNull();
    Notification::assertSentTo($this->me, OAuthConnectionNotice::class, fn ($n) => $n->provider === 'gitlab' && $n->connected === false);
});

it('refuses to remove the last way in', function () {
    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->me)->test(ConnectedAccounts::class)
        ->call('disconnect', 'github')
        ->assertHasErrors('disconnect');

    expect(SocialAccount::resolve('github', 'gh-me'))->not->toBeNull();
    Notification::assertNothingSent();
});

it('removes only the member\'s own connection', function () {
    $other = TestUser::factory()->create();
    SocialAccount::forceCreate(['user_id' => $other->getKey(), 'provider' => 'gitlab', 'provider_user_id' => 'gl-other']);
    SocialAccount::forceCreate(['user_id' => $this->me->getKey(), 'provider' => 'gitlab', 'provider_user_id' => 'gl-me']);
    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->me)->test(ConnectedAccounts::class)->call('disconnect', 'gitlab');

    expect(SocialAccount::resolve('gitlab', 'gl-other'))->not->toBeNull();
});

it('forgets an abandoned connection when another round trip starts', function () {
    // From the independent check: a connect abandoned at the provider left its
    // intent in the session, so a GET round trip another page started within
    // the step-up window connected whatever identity came back.
    confirmedAs($this, $this->me)->post(route('socialite.connect', 'gitlab'));

    $this->get(route('socialite.redirect', 'gitlab'));
    $this->oauth->asserts('gitlab', 'gl-planted', 'me@example.com');
    $this->get(route('socialite.callback', 'gitlab'))->assertSessionHasErrors('email');

    expect(SocialAccount::resolve('gitlab', 'gl-planted'))->toBeNull();
});

it('removes nothing when a concurrent removal took the other way in', function () {
    // From the independent check: two removes at once each counted two
    // connections and deleted one, leaving none. The removal re-checks after
    // deleting, under a lock on the member.
    SocialAccount::forceCreate(['user_id' => $this->me->getKey(), 'provider' => 'gitlab', 'provider_user_id' => 'gl-me']);
    session()->put('auth.password_confirmed_at', time());
    SocialAccount::deleting(function (SocialAccount $account) {
        if ($account->provider === 'gitlab') {
            DB::table('usarrs_social_accounts')->where('provider', 'github')->delete();
        }
    });

    Livewire::actingAs($this->me)->test(ConnectedAccounts::class)
        ->call('disconnect', 'gitlab')
        ->assertHasErrors('disconnect');

    expect(SocialAccount::resolve('gitlab', 'gl-me'))->not->toBeNull();
    Notification::assertNothingSent();
});
