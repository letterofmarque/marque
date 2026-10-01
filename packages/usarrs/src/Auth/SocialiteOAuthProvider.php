<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

use Laravel\Socialite\Facades\Socialite;
use Marque\Usarrs\Contracts\OAuthProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * OAuthProvider backed by laravel/socialite, which the consuming app installs
 * when it uses the socialite auth driver. Only this class names Socialite.
 */
class SocialiteOAuthProvider implements OAuthProvider
{
    public function redirect(string $provider): RedirectResponse
    {
        return Socialite::driver($provider)->redirect();
    }

    public function user(string $provider): OAuthIdentity
    {
        $user = Socialite::driver($provider)->user();

        return new OAuthIdentity(
            provider: $provider,
            id: (string) $user->getId(),
            email: $user->getEmail(),
            name: $user->getName(),
            nickname: $user->getNickname(),
        );
    }
}
