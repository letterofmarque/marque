<?php

declare(strict_types=1);
use Marque\Usarrs\Tests\TestUser;

// Spec #142 criterion 6. OAuth only exists under socialite mode. The routes
// used to be registered under every mode and gated only on the provider list —
// which defaults to ['github'] — so a password-mode install with
// laravel/socialite present had a live OAuth door it never chose.
//
// This file runs in the default (password) mode.

it('does not register the OAuth routes outside socialite mode', function () {
    expect(config('usarrs.auth_driver'))->toBe('password')
        ->and(config('usarrs.socialite_providers'))->toContain('github')
        ->and(app('router')->has('socialite.redirect'))->toBeFalse()
        ->and(app('router')->has('socialite.callback'))->toBeFalse();
});

it('404s the OAuth URLs outside socialite mode', function () {
    $this->get('/auth/github/redirect')->assertNotFound();
    $this->get('/auth/github/callback')->assertNotFound();
});

it('404s magic-link verification outside magic_link mode', function () {
    $user = TestUser::factory()->create();
    $token = app('auth.password.broker')->createToken($user);

    $this->get(route('magic-link.verify', ['token' => $token, 'email' => $user->email]))
        ->assertNotFound();

    $this->assertGuest();
});
