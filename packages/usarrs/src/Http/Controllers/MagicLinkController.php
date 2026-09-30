<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Marque\Usarrs\Auth\LoginCompletion;

class MagicLinkController
{
    public function showSentPage(): View
    {
        return view('usarrs::auth.magic-link-sent')
            ->layout(config('usarrs.layout', 'deck::layouts.app'));
    }

    public function verify(Request $request): RedirectResponse
    {
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

        // Through the seam, so a user with 2FA confirmed is challenged. This
        // path used to sign them straight in (Spec #142).
        return redirect(app(LoginCompletion::class)->begin($user, remember: true));
    }
}
