<?php

declare(strict_types=1);

// Issue #10883. Fortify 1.39 suppresses laravel/passkeys' routes to serve its
// own, and usarrs suppresses Fortify's, so with passkeys enabled the WebAuthn
// endpoints existed nowhere: registering a passkey couldn't work and passkey
// sign-in didn't exist. The old tests touched only the Livewire component and
// the model. These drive the real endpoints with a software authenticator.

use Marque\Usarrs\Tests\SoftAuthenticator;
use Marque\Usarrs\Tests\TestUser;

function registerPasskey(object $test, TestUser $user, SoftAuthenticator $authenticator): void
{
    $test->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $options = $test->getJson('/user/passkeys/options')->assertOk()->json('options');

    $test->postJson('/user/passkeys', [
        'name' => 'Laptop',
        'credential' => $authenticator->register($options),
    ])->assertSuccessful();
}

function signInWithPasskey(object $test, SoftAuthenticator $authenticator): mixed
{
    $options = $test->getJson('/passkeys/login/options')->assertOk()->json('options');

    return $test->postJson('/passkeys/login', ['credential' => $authenticator->signIn($options)]);
}

it('registers a passkey through the real endpoints', function () {
    $user = TestUser::factory()->create();

    registerPasskey($this, $user, new SoftAuthenticator);

    expect($user->passkeys()->count())->toBe(1);
});

it('signs in with that passkey through the real endpoints', function () {
    $user = TestUser::factory()->create();
    $authenticator = new SoftAuthenticator;
    registerPasskey($this, $user, $authenticator);
    auth()->logout();

    signInWithPasskey($this, $authenticator)->assertSuccessful();

    $this->assertAuthenticatedAs($user);
});

it('sends a passkey sign-in to the site root, not Fortify\'s /home', function () {
    // Fortify 1.39 overwrites passkeys.redirect with its own home, a page a
    // usarrs app doesn't have.
    $user = TestUser::factory()->create();
    $authenticator = new SoftAuthenticator;
    registerPasskey($this, $user, $authenticator);
    auth()->logout();

    expect(signInWithPasskey($this, $authenticator)->json('redirect'))->toBe(url('/'));
});

it('refuses a passkey sign-in by a banned user, with a 422 rather than a session (#10857)', function () {
    $user = TestUser::factory()->create();
    $authenticator = new SoftAuthenticator;
    registerPasskey($this, $user, $authenticator);
    auth()->logout();
    $user->forceFill(['status' => 'banned'])->save();

    signInWithPasskey($this, $authenticator)->assertUnprocessable();

    $this->assertGuest();
});

it('throttles passkey sign-in attempts', function () {
    // Fortify 1.39 replaces laravel/passkeys' throttle with its own limiter,
    // which is unset unless the app configured Fortify, so there was none.
    foreach (range(1, 6) as $_) {
        $this->getJson('/passkeys/login/options')->assertOk();
    }

    $this->getJson('/passkeys/login/options')->assertTooManyRequests();
});

it('asks for a confirmed password before managing passkeys', function () {
    $this->actingAs(TestUser::factory()->create());

    $this->getJson('/user/passkeys/options')->assertStatus(423);
});

it('offers passkey sign-in on the login page', function () {
    $this->get(route('login'))->assertSee(__('Sign in with a passkey'));
});
