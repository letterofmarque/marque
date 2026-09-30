<?php

declare(strict_types=1);

// Spec #142 criterion 3. Every interactive login finishes through one seam,
// which decides whether a two-factor challenge is due. Before this, each path
// made that decision itself — and the magic-link path simply didn't, so a user
// with 2FA confirmed was signed straight in by clicking an emailed link.

use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Livewire\Auth\Register;
use Marque\Usarrs\Livewire\Auth\TwoFactorChallenge;
use Marque\Usarrs\Tests\TestUser;
use PragmaRX\Google2FA\Google2FA;

function twoFactorUser(array $attributes = []): array
{
    $secret = app(Google2FA::class)->generateSecretKey();
    $user = TestUser::factory()->create([
        'email' => 'secure@example.com',
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
        ...$attributes,
    ]);

    return [$user, $secret];
}

function magicLinkFor(TestUser $user): string
{
    $token = app('auth.password.broker')->createToken($user);

    return route('magic-link.verify', ['token' => $token, 'email' => $user->email]);
}

describe('magic link', function () {
    // Verification only exists in magic_link mode (Spec #142).
    beforeEach(fn () => config()->set('usarrs.auth_driver', 'magic_link'));

    it('sends a user with 2FA confirmed to the challenge instead of signing them in', function () {
        config()->set('usarrs.two_factor.enabled', true);
        [$user] = twoFactorUser();

        $this->get(magicLinkFor($user))
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        expect(session('login.id'))->toBe($user->getAuthIdentifier());
    });

    it('still signs in a user without 2FA directly', function () {
        config()->set('usarrs.two_factor.enabled', true);
        $user = TestUser::factory()->create();

        $this->get(magicLinkFor($user))->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    });

    it('keeps "remember me" across the challenge, as the link always has', function () {
        config()->set('usarrs.two_factor.enabled', true);
        [$user] = twoFactorUser();

        $this->get(magicLinkFor($user));

        expect(session('login.remember'))->toBeTrue();
    });

    it('completes through the challenge with a valid code', function () {
        config()->set('usarrs.two_factor.enabled', true);
        [$user, $secret] = twoFactorUser();

        $this->get(magicLinkFor($user));

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', app(Google2FA::class)->getCurrentOtp($secret))
            ->call('challenge')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        expect(session()->has('login.id'))->toBeFalse();
    });
});

describe('the seam', function () {
    it('signs in and regenerates the session when no challenge is due', function () {
        $user = TestUser::factory()->create();
        $before = session()->getId();

        $destination = app(LoginCompletion::class)->begin($user, remember: false);

        expect($destination)->toBe(url('/'));
        $this->assertAuthenticatedAs($user);
        expect(session()->getId())->not->toBe($before);
    });

    it('hands off to the challenge without signing in when one is due', function () {
        config()->set('usarrs.two_factor.enabled', true);
        [$user] = twoFactorUser();

        $destination = app(LoginCompletion::class)->begin($user, remember: true);

        expect($destination)->toBe(route('two-factor.login'));
        $this->assertGuest();
        expect(session('login.id'))->toBe($user->getAuthIdentifier())
            ->and(session('login.remember'))->toBeTrue();
    });

    it('does not challenge a user whose 2FA is enabled but never confirmed', function () {
        config()->set('usarrs.two_factor.enabled', true);
        [$user] = twoFactorUser(['two_factor_confirmed_at' => null]);

        app(LoginCompletion::class)->begin($user, remember: false);

        $this->assertAuthenticatedAs($user);
    });
});

it('signs a newly registered user in through the seam', function () {
    Livewire::test(Register::class)
        ->set('name', 'New Person')
        ->set('email', 'new@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register');

    $this->assertAuthenticated();
});
