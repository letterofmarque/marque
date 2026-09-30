<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Marque\Usarrs\Enums\AuthDriver;

class PasswordResetController
{
    public function showForgotForm(): View
    {
        $this->ensureResetsAllowed();

        return view('usarrs::auth.forgot-password')
            ->layout(config('usarrs.layout', 'deck::layouts.app'));
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $this->ensureResetsAllowed();

        $request->validate(['email' => 'required|email']);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', __('If an account exists, a password reset link has been sent.'));
    }

    public function showResetForm(Request $request, string $token): View
    {
        $this->ensureResetsAllowed();

        return view('usarrs::auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ])->layout(config('usarrs.layout', 'deck::layouts.app'));
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->ensureResetsAllowed();

        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->update(['password' => Hash::make($password)]);
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __('Password reset successfully.'))
            : back()->withErrors(['email' => __($status)]);
    }

    /**
     * Resets exist only where the mode has passwords to reset — what
     * AuthDriver::supportsPasswordReset() has always declared and nothing
     * enforced (Spec #142).
     */
    private function ensureResetsAllowed(): void
    {
        abort_unless(AuthDriver::from(config('usarrs.auth_driver', 'password'))->supportsPasswordReset(), 404);
    }
}
