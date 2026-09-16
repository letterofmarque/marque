<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('login page is accessible', function () {
    $this->get(route('login'))
        ->assertOk();
});

test('register page is accessible', function () {
    $this->get(route('register'))
        ->assertOk();
});

test('register page returns 404 for invite_only driver', function () {
    config()->set('usarrs.auth_driver', 'invite_only');

    $this->get(route('register'))
        ->assertNotFound();
});

test('forgot password page is accessible', function () {
    $this->get(route('password.request'))
        ->assertOk();
});

test('logout requires authentication', function () {
    $this->post(route('logout'))
        ->assertRedirect();
});

test('authenticated user can logout', function () {
    $this->actingAs($this->user)
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});

test('login authenticates user with valid credentials', function () {
    $user = TestUser::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    Livewire::test(Login::class)
        ->set('email', 'test@example.com')
        ->set('password', 'password123')
        ->call('login')
        ->assertRedirect('/');
});

test('login fails with invalid credentials', function () {
    Livewire::test(Login::class)
        ->set('email', 'test@example.com')
        ->set('password', 'wrong')
        ->call('login')
        ->assertHasErrors('email');
});

/*
|--------------------------------------------------------------------------
| Guest-only routes (job #10698)
|--------------------------------------------------------------------------
|
| The group these live in was commented "Guest routes" but carried only
| config('usarrs.middleware'), which defaults to ['web'] — no guest middleware
| anywhere. So a logged-in user could open the login form, the register form,
| or the 2FA challenge. Confusing rather than dangerous (submitting just
| re-authenticates them), but every consuming app hits it and works around it
| separately; twentyt re-registered /login in its own routes/web.php to get
| the redirect.
|
| Only the genuinely guest-only routes are gated. Several routes in that same
| group are legitimately reachable while authenticated — see the test below,
| which is the reason a single config key could not express this.
*/

test('login redirects an authenticated user', function () {
    $this->actingAs($this->user)
        ->get(route('login'))
        ->assertRedirect();
});

test('register redirects an authenticated user', function () {
    $this->actingAs($this->user)
        ->get(route('register'))
        ->assertRedirect();
});

test('two-factor challenge redirects an authenticated user', function () {
    // Enable 2FA and seed the pending-login session, or the component aborts
    // 403 in mount() before middleware behaviour is observable — the test
    // would then pass without the guest gate existing at all.
    config()->set('usarrs.two_factor.enabled', true);
    session()->put('login.id', $this->user->getKey());

    $this->actingAs($this->user)
        ->get(route('two-factor.login'))
        ->assertRedirect();
});

test('forgot-password redirects an authenticated user', function () {
    // A logged-in user cannot be in the "I forgot my password" flow. The
    // authenticated equivalent is password.confirm, which already exists.
    $this->actingAs($this->user)
        ->get(route('password.request'))
        ->assertRedirect();

    // Not asserting a specific target: `guest` redirects to RouteServiceProvider's
    // HOME, which the consuming app owns. That it redirects at all is the contract.
    $this->actingAs($this->user)
        ->post(route('password.email'), ['email' => $this->user->email])
        ->assertRedirect();
});

test('guest can still reach every guest-only route', function () {
    // The gate must not lock out the people it exists for.
    $this->get(route('login'))->assertOk();
    $this->get(route('register'))->assertOk();
    $this->get(route('password.request'))->assertOk();
});

test('routes an authenticated user may legitimately hit are NOT gated', function () {
    // This is why `middleware` could not simply become ['web','guest']: these
    // live in the same group and have real authenticated uses —
    //
    //   reset-password    following a reset link from email while logged in
    //   magic-link.verify clicking a magic link on another device / account
    //   socialite.*       linking an additional provider to an existing account
    //
    // Gating the whole group would break all three.
    $this->actingAs($this->user)
        ->get(route('password.reset', ['token' => 'a-token']))
        ->assertOk();

    $this->actingAs($this->user)
        ->get(route('magic-link.sent'))
        ->assertOk();
});

test('the guest middleware stack is configurable', function () {
    // Mirrors auth_middleware, so a consumer can point it at their own guard
    // or drop the redirect entirely without re-registering the routes.
    expect(config('usarrs.guest_middleware'))->toBe(['web', 'guest']);
});
