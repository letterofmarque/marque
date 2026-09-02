<?php

declare(strict_types=1);

// manage_auth is the one-way escape hatch (Spec #92): when false, usarrs
// registers none of its own auth routes/components, leaving a power-user
// free to wire up fully custom login/register/2FA/passkeys. The bar here is
// higher than the existing admin.enabled pattern (which 404s via a mount()
// check while the route stays registered) — these routes and Livewire tags
// must not exist at all under manage_auth=false, per CP #514's acceptance
// criteria. That's why this suite needs its own TestCase subclass: the
// routes bind once at ServiceProvider::boot(), so the config has to be set
// during defineEnvironment(), before boot runs — a runtime config()->set()
// in the test body is too late.

use Livewire\Livewire;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    $this->admin = TestUser::factory()->admin()->create();
});

test('login route does not exist when manage_auth is false', function () {
    $this->get('/login')->assertNotFound();
});

test('register route does not exist when manage_auth is false', function () {
    $this->get('/register')->assertNotFound();
});

test('two factor challenge route does not exist when manage_auth is false', function () {
    $this->get('/two-factor-challenge')->assertNotFound();
});

test('password reset routes do not exist when manage_auth is false', function () {
    $this->get('/forgot-password')->assertNotFound();
    $this->get('/reset-password/some-token')->assertNotFound();
});

test('magic link and socialite routes do not exist when manage_auth is false', function () {
    $this->get('/auth/magic-link/verify')->assertNotFound();
    $this->get('/auth/github/redirect')->assertNotFound();
});

test('logout route does not exist when manage_auth is false', function () {
    $this->actingAs($this->user)
        ->post('/logout')
        ->assertNotFound();
});

// Livewire::test(SomeClass::class) resolves by FQCN and bypasses alias
// registration entirely — it's the wrong tool here and fails for the wrong
// reason (an unrelated view error) even when the alias genuinely isn't
// registered. Livewire::exists() is the alias-lookup Blade's <livewire:.../>
// actually uses, so it's what proves the tag is gone.
test('auth livewire components are not registered when manage_auth is false', function () {
    expect(Livewire::exists('usarrs-login'))->toBeFalse();
});

test('register livewire component is not registered when manage_auth is false', function () {
    expect(Livewire::exists('usarrs-register'))->toBeFalse();
});

test('two factor setup livewire component is not registered when manage_auth is false', function () {
    expect(Livewire::exists('usarrs-two-factor-setup'))->toBeFalse();
});

test('passkey management livewire component is not registered when manage_auth is false', function () {
    expect(Livewire::exists('usarrs-passkey-management'))->toBeFalse();
});

test('email verification routes do not exist when manage_auth is false', function () {
    // job #10602 Gap 7 / Spec #96. GET-only assertion for verification.verify
    // (its {id}/{hash} segments make a bare GET without valid params a poor
    // fit for assertNotFound() semantics anyway — the important thing is the
    // route group as a whole, verification.notice/send, is unregistered).
    $this->actingAs($this->user)->get('/email/verify')->assertNotFound();
    $this->actingAs($this->user)->post('/email/verification-notification')->assertNotFound();
});

test('password confirm route does not exist when manage_auth is false', function () {
    $this->actingAs($this->user)->get('/user/confirm-password')->assertNotFound();
});

test('password confirm livewire component is not registered when manage_auth is false', function () {
    expect(Livewire::exists('usarrs-password-confirm'))->toBeFalse();
});

test('fortify routes remain suppressed when manage_auth is false', function () {
    $this->post('/register', [
        'name' => 'Evil User',
        'email' => 'evil@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});

test('profile show and edit still register when manage_auth is false', function () {
    $this->actingAs($this->user)->get(route('profile.show'))->assertOk();
    $this->actingAs($this->user)->get(route('profile.edit'))->assertOk();
});

test('announce key management still registers when manage_auth is false', function () {
    $this->actingAs($this->user)->get(route('profile.stats'))->assertOk();
});

test('invites still register when manage_auth is false', function () {
    config()->set('usarrs.invites.enabled', true);

    $this->actingAs($this->user)->get(route('invites.index'))->assertOk();
});

test('admin panel still registers when manage_auth is false', function () {
    $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk();
});
