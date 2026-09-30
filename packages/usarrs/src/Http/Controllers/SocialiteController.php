<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Auth\OAuthIdentity;
use Marque\Usarrs\Auth\RegistrationRules;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Livewire\Auth\ConfirmOAuthLink;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Notifications\OAuthLinkConfirmation;
use Marque\Usarrs\Services\InviteService;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class SocialiteController
{
    public function __construct(
        private readonly OAuthProvider $oauth,
        private readonly RegistrationRules $rules,
        private readonly InviteService $invites,
    ) {}

    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        $this->validateProvider($provider);

        // There is no registration form under socialite mode to type an invite
        // into, so it rides the round trip: /auth/github/redirect?invite=CODE.
        session()->put('usarrs.oauth.invite', $request->query('invite'));

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

        // Signed in already: this is connecting a provider to *this* account,
        // never a login. It used to be treated as one — an identity linked to
        // someone else switched the user into that account (Spec #142).
        if (auth()->check()) {
            return $this->connectToCurrentUser($identity);
        }

        $link = SocialAccount::resolve($identity->provider, $identity->id);

        if ($link !== null) {
            return redirect(app(LoginCompletion::class)->begin($link->user, remember: true));
        }

        // Not linked to anyone. Never sign in on the email — that is the
        // takeover. If it matches an account, ask that account's own inbox.
        $holder = $identity->email === null ? null : $this->userModel()::where('email', $identity->email)->first();

        if ($holder !== null) {
            $this->sendConfirmation($holder, $identity);

            return redirect()->route('login')->with('status', __(
                'That :provider account isn\'t connected here yet. If an account here uses its email address, we\'ve sent it a link to connect them — check your email.',
                ['provider' => ucfirst($provider)],
            ));
        }

        return $this->register($identity);
    }

    /**
     * A new account for an identity nobody here has — only where /register
     * would allow one, under the same rules (Spec #142). The callback used to
     * create an account for anything it didn't recognise, under every mode,
     * and sign it in unverified.
     */
    private function register(OAuthIdentity $identity): RedirectResponse
    {
        $inviteCode = session()->pull('usarrs.oauth.invite');

        if ($identity->email === null) {
            return $this->refuse(__(':provider didn\'t share an email address, so an account can\'t be made from it.', ['provider' => ucfirst($identity->provider)]));
        }

        if (($refusal = $this->rules->refusal($inviteCode)) !== null) {
            return $this->refuse($refusal);
        }

        $user = $this->userModel()::create([
            'name' => $identity->name ?? $identity->email,
            'email' => $identity->email,
            // Nobody knows it and nothing can reset it under socialite mode:
            // this account is reached through its OAuth link only.
            'password' => Hash::make(Str::random(64)),
        ]);

        if (($invite = $this->rules->validInvite($inviteCode)) !== null) {
            $this->invites->redeem($invite, $user);
        }

        SocialAccount::forceCreate([
            'user_id' => $user->getKey(),
            'provider' => $identity->provider,
            'provider_user_id' => $identity->id,
        ]);

        // Unverified, like any other new account. The provider's say-so is not
        // this site's proof that the address is theirs.
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return redirect(app(LoginCompletion::class)->begin($user, remember: true));
    }

    /**
     * Email the account holder a link to connect this identity — unless the
     * account already has one for this provider, when connecting would be
     * refused anyway and the mail would only be noise (or a way to spam them).
     *
     * The link carries a one-time token; what it would connect is held here,
     * server-side, and consumed when the holder confirms on the page it opens
     * (Livewire\Auth\ConfirmOAuthLink).
     */
    private function sendConfirmation(Authenticatable $holder, OAuthIdentity $identity): void
    {
        $alreadyHasOne = SocialAccount::query()
            ->where('user_id', $holder->getAuthIdentifier())
            ->where('provider', $identity->provider)
            ->exists();

        if ($alreadyHasOne) {
            return;
        }

        $label = trim(($identity->name ?? '').($identity->email !== null ? " ({$identity->email})" : ''));
        $label = $label !== '' ? $label : $identity->id;

        $token = Str::random(40);
        Cache::put(ConfirmOAuthLink::CACHE_PREFIX.$token, [
            'user' => $holder->getAuthIdentifier(),
            'provider' => $identity->provider,
            'provider_user_id' => $identity->id,
            'label' => $label,
        ], now()->addMinutes(60));

        $holder->notify(new OAuthLinkConfirmation(
            $identity->provider,
            URL::temporarySignedRoute('socialite.link.confirm', now()->addMinutes(60), [
                'provider' => $identity->provider,
                'token' => $token,
            ]),
            $label,
        ));
    }

    /**
     * Link an identity to the signed-in user. The user is already proven, so
     * the provider's email plays no part; the session is never changed.
     */
    private function connectToCurrentUser(OAuthIdentity $identity): RedirectResponse
    {
        $user = auth()->user();
        $provider = ucfirst($identity->provider);
        $existing = SocialAccount::resolve($identity->provider, $identity->id);

        if ($existing !== null) {
            return $existing->user_id === $user->getAuthIdentifier()
                ? redirect()->route('profile.show')->with('status', __(':provider is already connected to your account.', ['provider' => $provider]))
                : redirect()->route('profile.show')->withErrors(['email' => __('That :provider account is already connected to a different account.', ['provider' => $provider])]);
        }

        $alreadyHasOne = SocialAccount::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('provider', $identity->provider)
            ->exists();

        if ($alreadyHasOne) {
            return redirect()->route('profile.show')
                ->withErrors(['email' => __('Your account is already connected to a different :provider account.', ['provider' => $provider])]);
        }

        SocialAccount::forceCreate([
            'user_id' => $user->getAuthIdentifier(),
            'provider' => $identity->provider,
            'provider_user_id' => $identity->id,
        ]);

        return redirect()->route('profile.show')->with('status', __(':provider connected.', ['provider' => $provider]));
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
