<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Enums\AuthDriver;

class MagicLinkController
{
    public function showSentPage(): View
    {
        return view('usarrs::auth.magic-link-sent')
            ->layout(config('usarrs.layout', 'deck::layouts.app'));
    }

    public function verify(Request $request): RedirectResponse
    {
        // Only in magic_link mode. The token is a password-broker token, so
        // this endpoint used to sign in anyone holding a reset link under every
        // mode — including socialite, which is meant to be OAuth only (Spec #142).
        abort_unless(AuthDriver::from(config('usarrs.auth_driver', 'password')) === AuthDriver::MagicLink, 404);

        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
        ]);

        $model = config('trove.user_model', 'App\\Models\\User');
        $user = $model::where('email', $request->email)->first();

        if (! $user || ! app('auth.password.broker')->tokenExists($user, $request->token)) {
            return redirect()->route('login')
                ->withErrors(['email' => __('This login link is invalid or has expired.')]);
        }

        app('auth.password.broker')->deleteToken($user);

        // The link went to this address and came back: the same proof a
        // verification link gives. Without it a magic-link member who skipped
        // the verification mail had nothing to confirm it's them with (Job #355).
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        // Through the seam, so a user with 2FA confirmed is challenged. This
        // path used to sign them straight in (Spec #142).
        // The login form's remember-me choice, carried in the link. It used to
        // be on for every magic-link sign-in (#10858).
        return redirect(app(LoginCompletion::class)->begin($user, remember: $request->boolean('remember')));
    }
}
