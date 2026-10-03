<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmPassword;
use Livewire\Attributes\Title;
use Marque\Usarrs\Livewire\Component;

/**
 * job #10602 Gap 7 (continued): the "please re-enter your password" prompt
 * Laravel's stock 'password.confirm' middleware (RequirePassword) redirects
 * to. Fortify's routes are suppressed unconditionally, and usarrs never
 * re-registered this one — see Spec #96.
 *
 * Uses Fortify's own ConfirmPassword action directly (the same class its
 * own ConfirmablePasswordController calls) as a library, matching the
 * pattern established for 2FA/passkeys — a Livewire component here, unlike
 * EmailVerificationController's plain-controller shape, since no orphaned
 * view pre-existed pulling this one toward that shape (see Spec #96's
 * Decision on the two surfaces using different mechanisms deliberately).
 */
#[Title('Confirm Password')]
class PasswordConfirm extends Component
{
    public string $password = '';

    public function confirm(ConfirmPassword $confirmPassword, StatefulGuard $guard): void
    {
        // Five a minute per user (#10856): a hijacked session could otherwise
        // guess the password here without going near the login form.
        $key = 'usarrs.confirm-password:'.auth()->id();

        // Counted before the check, atomically (Job #141 review).
        if (RateLimiter::hit($key) > 5) {
            throw ValidationException::withMessages([
                'password' => [__('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])],
            ]);
        }

        $confirmed = $confirmPassword($guard, auth()->user(), $this->password);

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'password' => [__('This password does not match our records.')],
            ]);
        }

        RateLimiter::clear($key);
        session()->put('auth.password_confirmed_at', Date::now()->unix());

        // Back to the page that asked, as Laravel's own confirm flow does: the
        // password.confirm middleware records it (Job #141 review).
        $this->redirect(session()->pull('url.intended', url('/')), navigate: true);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.password-confirm');
    }
}
