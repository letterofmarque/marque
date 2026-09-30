<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Models\SocialAccount;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class SocialiteController
{
    public function __construct(private readonly OAuthProvider $oauth) {}

    public function redirect(string $provider): SymfonyRedirect
    {
        $this->validateProvider($provider);

        return $this->oauth->redirect($provider);
    }

    /**
     * Resolve the returning user by linked identity — (provider, provider's
     * user id) — and nothing else (Spec #142).
     *
     * This used to look the user up by the email the provider reported and
     * sign in whoever it found, creating an account if nobody matched: a
     * provider asserting the admin's address signed the asserter in as the
     * admin, past 2FA and past closed registration (job #10818).
     */
    public function callback(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        $identity = $this->oauth->user($provider);
        $link = SocialAccount::resolve($identity->provider, $identity->id);

        if ($link === null) {
            // Not linked to anyone. Never fall back to matching the email —
            // that is the takeover. (Spec #142 CP4 turns an email match into a
            // confirmation sent to the account's own address; CP5 creates an
            // account where registration rules allow.)
            return redirect()->route('login')
                ->withErrors(['email' => __('No account here is linked to that :provider account.', ['provider' => ucfirst($provider)])]);
        }

        return redirect(app(LoginCompletion::class)->begin($link->user, remember: true));
    }

    protected function validateProvider(string $provider): void
    {
        $allowed = config('usarrs.socialite_providers', []);
        abort_unless(in_array($provider, $allowed, true), 404);
    }
}
