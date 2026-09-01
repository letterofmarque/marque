<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Date;
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
        $confirmed = $confirmPassword($guard, auth()->user(), $this->password);

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'password' => [__('This password does not match our records.')],
            ]);
        }

        session()->put('auth.password_confirmed_at', Date::now()->unix());

        $this->redirect(url('/'), navigate: true);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.password-confirm');
    }
}
