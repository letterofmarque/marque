<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Notifications\OAuthLinkConfirmation;
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

        if ($link !== null) {
            return redirect(app(LoginCompletion::class)->begin($link->user, remember: true));
        }

        // Not linked to anyone. Never sign in on the email — that is the
        // takeover. If it matches an account, ask that account's own inbox.
        $holder = $identity->email === null ? null : $this->userModel()::where('email', $identity->email)->first();

        if ($holder !== null) {
            $holder->notify(new OAuthLinkConfirmation($provider, URL::temporarySignedRoute(
                'socialite.link.confirm',
                now()->addMinutes(60),
                ['provider' => $provider, 'user' => $holder->getKey(), 'provider_user_id' => $identity->id],
            )));

            return redirect()->route('login')->with('status', __(
                'That :provider account isn\'t connected here yet. If an account here uses its email address, we\'ve sent it a link to connect them — check your email.',
                ['provider' => ucfirst($provider)],
            ));
        }

        return redirect()->route('login')
            ->withErrors(['email' => __('No account here is linked to that :provider account.', ['provider' => ucfirst($provider)])]);
    }

    /**
     * The emailed link, followed. The `signed` middleware has already refused
     * an expired or altered URL; what is left is whether the link can still be
     * made.
     */
    public function confirmLink(Request $request, string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        $user = $this->userModel()::find($request->query('user'));
        abort_if($user === null, 404);

        $providerUserId = (string) $request->query('provider_user_id');
        $existing = SocialAccount::resolve($provider, $providerUserId);

        if ($existing !== null && $existing->user_id !== $user->getKey()) {
            return $this->refuse(__('That :provider account is already connected to a different account.', ['provider' => ucfirst($provider)]));
        }

        if ($existing === null) {
            $alreadyHasOne = SocialAccount::query()
                ->where('user_id', $user->getKey())
                ->where('provider', $provider)
                ->exists();

            if ($alreadyHasOne) {
                return $this->refuse(__('This account is already connected to a different :provider account.', ['provider' => ucfirst($provider)]));
            }

            SocialAccount::forceCreate([
                'user_id' => $user->getKey(),
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
            ]);
        }

        return redirect(app(LoginCompletion::class)->begin($user, remember: true));
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    private function userModel(): string
    {
        return config('trove.user_model', 'App\\Models\\User');
    }

    protected function validateProvider(string $provider): void
    {
        $allowed = config('usarrs.socialite_providers', []);
        abort_unless(in_array($provider, $allowed, true), 404);
    }
}
