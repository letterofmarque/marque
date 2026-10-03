<?php

declare(strict_types=1);

// Issue #10856. Nothing in usarrs was rate-limited: the two-factor challenge
// took unlimited guesses per pending login (a 6-digit code falls in hours),
// a code could be replayed inside its window, and password login was
// unthrottled.

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Auth\Login;
use Marque\Usarrs\Livewire\Auth\PasswordConfirm;
use Marque\Usarrs\Livewire\Auth\TwoFactorChallenge;
use Marque\Usarrs\Tests\TestUser;
use PragmaRX\Google2FA\Google2FA;

function challengedUser(): array
{
    config()->set('usarrs.two_factor.enabled', true);

    $secret = app(Google2FA::class)->generateSecretKey();
    $user = TestUser::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one', 'recovery-two'])),
    ]);

    session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

    return [$user, $secret];
}

function wrongCodes(int $times): void
{
    foreach (range(1, $times) as $_) {
        Livewire::test(TwoFactorChallenge::class)->set('code', '000000')->call('challenge')->assertHasErrors('code');
    }
}

describe('the two-factor challenge', function () {
    it('refuses even the right code once five attempts have failed', function () {
        [, $secret] = challengedUser();

        wrongCodes(5);

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', app(Google2FA::class)->getCurrentOtp($secret))
            ->call('challenge')
            ->assertHasErrors('code');

        $this->assertGuest();
    });

    it('counts recovery-code guesses against the same limit', function () {
        challengedUser();

        wrongCodes(5);

        Livewire::test(TwoFactorChallenge::class)
            ->set('recoveryCode', 'recovery-one')
            ->call('challengeWithRecoveryCode')
            ->assertHasErrors('recoveryCode');

        $this->assertGuest();
    });

    it('still lets the right code through after four misses, and starts the count again', function () {
        [$user, $secret] = challengedUser();

        wrongCodes(4);

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', app(Google2FA::class)->getCurrentOtp($secret))
            ->call('challenge')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        expect(RateLimiter::attempts('usarrs.two-factor:'.$user->getKey()))->toBe(0);
    });

    it('refuses a code that has already been used to sign in', function () {
        [$user, $secret] = challengedUser();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        Livewire::test(TwoFactorChallenge::class)->set('code', $code)->call('challenge')->assertRedirect('/');
        Auth::logout();

        session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', $code)
            ->call('challenge')
            ->assertHasErrors('code');

        $this->assertGuest();
    });

    it('does not let one user\'s used code block another user who happens to share it', function () {
        // Fortify keys its replay cache on the code alone. usarrs keys it on
        // the user, so a coincidence across accounts refuses nobody.
        [$first, $secret] = challengedUser();
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        Livewire::test(TwoFactorChallenge::class)->set('code', $code)->call('challenge');
        Auth::logout();

        $second = TestUser::factory()->create([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);
        session()->put(['login.id' => $second->getKey(), 'login.remember' => false]);

        Livewire::test(TwoFactorChallenge::class)->set('code', $code)->call('challenge')->assertRedirect('/');

        $this->assertAuthenticatedAs($second);
    });
});

describe('password login', function () {
    beforeEach(function () {
        $this->user = TestUser::factory()->create(['email' => 'member@example.com', 'password' => bcrypt('right-password')]);
    });

    function attempt(string $email, string $password): mixed
    {
        return Livewire::test(Login::class)->set('email', $email)->set('password', $password)->call('login');
    }

    it('refuses even the right password after five failures for that email', function () {
        foreach (range(1, 5) as $_) {
            attempt('member@example.com', 'wrong')->assertHasErrors('email');
        }

        attempt('member@example.com', 'right-password')->assertHasErrors('email');

        $this->assertGuest();
    });

    it('says to wait, not that the credentials are wrong', function () {
        foreach (range(1, 5) as $_) {
            attempt('member@example.com', 'wrong');
        }

        $message = attempt('member@example.com', 'right-password')->errors()->first('email');

        expect($message)->toContain('Too many login attempts');
    });

    it('limits per email, so one account under attack does not lock out another', function () {
        $other = TestUser::factory()->create(['email' => 'other@example.com', 'password' => bcrypt('other-password')]);

        foreach (range(1, 5) as $_) {
            attempt('member@example.com', 'wrong');
        }

        attempt('other@example.com', 'other-password')->assertRedirect('/');

        $this->assertAuthenticatedAs($other);
    });

    it('treats the email case-insensitively, so MEMBER@ does not get a fresh five', function () {
        foreach (['MEMBER@example.com', 'Member@example.com', 'mEmber@example.com', 'MEMber@example.com', 'memBER@example.com'] as $variant) {
            attempt($variant, 'wrong');
        }

        attempt('member@example.com', 'right-password')->assertHasErrors('email');

        $this->assertGuest();
    });

    it('clears the count on a successful sign-in', function () {
        foreach (range(1, 4) as $_) {
            attempt('member@example.com', 'wrong');
        }

        attempt('member@example.com', 'right-password')->assertRedirect('/');
        Auth::logout();

        foreach (range(1, 4) as $_) {
            attempt('member@example.com', 'wrong');
        }

        attempt('member@example.com', 'right-password')->assertRedirect('/');
    });
});

describe('password confirmation', function () {
    // The re-enter-your-password prompt checks the password too, so a hijacked
    // session could guess it there without ever touching the login form.
    it('refuses even the right password after five failures', function () {
        $user = TestUser::factory()->create(['password' => bcrypt('right-password')]);

        foreach (range(1, 5) as $_) {
            Livewire::actingAs($user)->test(PasswordConfirm::class)
                ->set('password', 'wrong')->call('confirm')->assertHasErrors('password');
        }

        Livewire::actingAs($user)->test(PasswordConfirm::class)
            ->set('password', 'right-password')->call('confirm')->assertHasErrors('password');

        expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
    });
});
