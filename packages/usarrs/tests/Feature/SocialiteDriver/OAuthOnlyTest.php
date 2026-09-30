<?php

declare(strict_types=1);

// Spec #142 criteria 5 and 6, and job #10802. socialite mode is documented as
// OAuth only — "the only way in is an OAuth provider". Until now only the view
// agreed: the password form was hidden while the server still accepted a
// password login, a password registration, and a magic-link token.
//
// Every test here calls the server path directly. A test that reads the view
// is what let this ship.

use Livewire\Livewire;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create([
        'email' => 'member@example.com',
        'password' => bcrypt('password123'),
    ]);
});

it('registers the OAuth routes under socialite mode', function () {
    expect(app('router')->has('socialite.redirect'))->toBeTrue()
        ->and(app('router')->has('socialite.callback'))->toBeTrue();
});

it('refuses a password login with valid credentials', function () {
    Livewire::test(Login::class)
        ->set('email', 'member@example.com')
        ->set('password', 'password123')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('does not serve the password registration form', function () {
    $this->get('/register')->assertNotFound();
});

it('refuses a password registration even from a component mounted before the switch', function () {
    // A page loaded under another mode, submitted after the operator changed
    // it — the action must check for itself, not trust mount().
    config()->set('usarrs.auth_driver', 'password');
    $component = Livewire::test(Register::class);
    config()->set('usarrs.auth_driver', 'socialite');

    $component
        ->set('name', 'Sneaky')
        ->set('email', 'sneaky@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertNotFound();

    expect(TestUser::where('email', 'sneaky@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

it('refuses a magic-link token — email is not a way in either', function () {
    $token = app('auth.password.broker')->createToken($this->user);

    $this->get(route('magic-link.verify', ['token' => $token, 'email' => $this->user->email]))
        ->assertNotFound();

    $this->assertGuest();
});

it('refuses to send password reset links it has nothing to reset for', function () {
    $this->get(route('password.request'))->assertNotFound();
    $this->post(route('password.email'), ['email' => $this->user->email])->assertNotFound();
});
