<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Livewire\Component;
use PragmaRX\Google2FA\Google2FA;

#[Title('Two-Factor Challenge')]
class TwoFactorChallenge extends Component
{
    // Five guesses a minute per pending login, codes and recovery codes
    // together (#10856). Keyed on the login, not the IP, as Fortify does:
    // whoever is here already has the password, and an IP key would let them
    // rotate addresses for a fresh five.
    private const MAX_ATTEMPTS = 5;

    #[Validate('required|string')]
    public string $code = '';

    public string $recoveryCode = '';

    public function mount(): void
    {
        abort_unless(config('usarrs.two_factor.enabled', false), 403);
        abort_unless(session()->has('login.id'), 403);
    }

    public function challenge(Google2FA $engine): void
    {
        $this->validate();

        $user = $this->pendingUser();
        $this->ensureNotThrottled($user, 'code');

        if (! $this->verifyOnce($engine, $user)) {
            RateLimiter::hit($this->throttleKey($user));

            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ]);
        }

        $this->completeLogin($user);
    }

    public function challengeWithRecoveryCode(): void
    {
        $this->validate(['recoveryCode' => 'required|string']);

        $user = $this->pendingUser();
        $this->ensureNotThrottled($user, 'recoveryCode');
        $codes = $user->recoveryCodes();

        if (! in_array($this->recoveryCode, $codes, true)) {
            RateLimiter::hit($this->throttleKey($user));

            throw ValidationException::withMessages([
                'recoveryCode' => [__('The provided recovery code was invalid.')],
            ]);
        }

        $user->replaceRecoveryCode($this->recoveryCode);

        $this->completeLogin($user);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.two-factor-challenge');
    }

    private function pendingUser(): mixed
    {
        $model = config('trove.user_model', 'App\\Models\\User');

        $user = $model::find(session('login.id'));

        abort_unless($user, 403);

        return $user;
    }

    /**
     * A code works once (#10856). The last accepted code's timestamp is kept
     * per user, and only a newer one is accepted, so a code seen over a
     * shoulder or in a log can't be replayed inside its window. Fortify's own
     * provider keys this on the code alone, which lets two users who happen to
     * share a code block each other.
     */
    private function verifyOnce(Google2FA $engine, mixed $user): bool
    {
        $key = 'usarrs.two-factor.used:'.$user->getKey();

        $timestamp = $engine->verifyKeyNewer(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            $this->code,
            Cache::get($key),
        );

        if ($timestamp === false) {
            return false;
        }

        if ($timestamp === true) {
            $timestamp = $engine->getTimestamp();
        }

        Cache::put($key, $timestamp, ($engine->getWindow() ?: 1) * 60 * 2);

        return true;
    }

    private function ensureNotThrottled(mixed $user, string $field): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($user), self::MAX_ATTEMPTS)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => [__('Too many attempts. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($this->throttleKey($user)),
            ])],
        ]);
    }

    private function throttleKey(mixed $user): string
    {
        return 'usarrs.two-factor:'.$user->getKey();
    }

    private function completeLogin(mixed $user): void
    {
        RateLimiter::clear($this->throttleKey($user));

        app(LoginCompletion::class)->finish($user, (bool) session('login.remember', false));

        $this->redirect(url('/'), navigate: true);
    }
}
