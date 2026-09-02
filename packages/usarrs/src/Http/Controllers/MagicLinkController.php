<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MagicLinkController
{
    public function showSentPage(): View
    {
        return view('usarrs::auth.magic-link-sent')
            ->layout(config('usarrs.layout', 'ise::layouts.app'));
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

        Auth::login($user, remember: true);
        session()->regenerate();

        return redirect('/');
    }
}
