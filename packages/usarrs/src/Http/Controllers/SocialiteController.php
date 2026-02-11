<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class SocialiteController
{
    public function redirect(string $provider): SymfonyRedirect
    {
        $this->validateProvider($provider);

        return \Laravel\Socialite\Facades\Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        $socialUser = \Laravel\Socialite\Facades\Socialite::driver($provider)->user();

        $model = config('trove.user_model', 'App\\Models\\User');
        $user = $model::where('email', $socialUser->getEmail())->first();

        if (! $user) {
            $user = $model::create([
                'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                'email' => $socialUser->getEmail(),
                'password' => Hash::make(bin2hex(random_bytes(16))),
            ]);
        }

        Auth::login($user, remember: true);
        session()->regenerate();

        return redirect('/');
    }

    protected function validateProvider(string $provider): void
    {
        $allowed = config('usarrs.socialite_providers', []);
        abort_unless(in_array($provider, $allowed, true), 404);
    }
}
