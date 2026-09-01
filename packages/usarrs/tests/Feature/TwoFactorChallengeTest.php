<?php

declare(strict_types=1);

use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Livewire\Auth\TwoFactorChallenge;
use Marque\Usarrs\Tests\TestUser;
use PragmaRX\Google2FA\Google2FA;

test('login does not challenge when two factor is disabled for the user', function () {
    config()->set('usarrs.two_factor.enabled', true);

    $user = TestUser::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    Livewire::test(Login::class)
        ->set('email', 'test@example.com')
        ->set('password', 'password123')
        ->call('login')
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

test('login does not challenge when the two factor config is off, even if the user has it enabled', function () {
    config()->set('usarrs.two_factor.enabled', false);

    $secret = app(Google2FA::class)->generateSecretKey();
    $user = TestUser::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ]);

    Livewire::test(Login::class)
        ->set('email', 'test@example.com')
        ->set('password', 'password123')
        ->call('login')
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

test('login redirects to the two factor challenge when the user has it enabled', function () {
    config()->set('usarrs.two_factor.enabled', true);

    $secret = app(Google2FA::class)->generateSecretKey();
    TestUser::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ]);

    Livewire::test(Login::class)
        ->set('email', 'test@example.com')
        ->set('password', 'password123')
        ->call('login')
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('unconfirmed two factor setup does not trigger a challenge', function () {
    config()->set('usarrs.two_factor.enabled', true);

    $secret = app(Google2FA::class)->generateSecretKey();
    $user = TestUser::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => null,
    ]);

    Livewire::test(Login::class)
        ->set('email', 'test@example.com')
        ->set('password', 'password123')
        ->call('login')
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

test('challenge completes login with a valid code', function () {
    config()->set('usarrs.two_factor.enabled', true);

    $secret = app(Google2FA::class)->generateSecretKey();
    $user = TestUser::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ]);

    session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

    $validCode = app(Google2FA::class)->getCurrentOtp($secret);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', $validCode)
        ->call('challenge')
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

test('challenge rejects an invalid code', function () {
    config()->set('usarrs.two_factor.enabled', true);

    $secret = app(Google2FA::class)->generateSecretKey();
    $user = TestUser::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ]);

    session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', '000000')
        ->call('challenge')
        ->assertHasErrors('code');

    $this->assertGuest();
});

test('challenge completes login with a valid recovery code', function () {
    config()->set('usarrs.two_factor.enabled', true);

    $secret = app(Google2FA::class)->generateSecretKey();
    $recoveryCodes = ['one-recovery-code', 'another-recovery-code'];
    $user = TestUser::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($recoveryCodes)),
    ]);

    session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('recoveryCode', 'one-recovery-code')
        ->call('challengeWithRecoveryCode')
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

test('challenge is unreachable without a pending login session', function () {
    config()->set('usarrs.two_factor.enabled', true);

    Livewire::test(TwoFactorChallenge::class)
        ->assertForbidden();
});
