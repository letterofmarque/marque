<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetController
{
    public function showForgotForm(): View
    {
        return view('usarrs::auth.forgot-password')
            ->layout(config('usarrs.layout', 'id::layouts.app'));
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', __('If an account exists, a password reset link has been sent.'));
    }

    public function showResetForm(Request $request, string $token): View
    {
        return view('usarrs::auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ])->layout(config('usarrs.layout', 'id::layouts.app'));
    }

    public function reset(Request $request): RedirectResponse
    {
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
}
