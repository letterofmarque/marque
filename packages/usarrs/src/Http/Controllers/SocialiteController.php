<?php

declare(strict_types=1);

namespace Marque\Usarrs\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Auth\OAuthIdentity;
use Marque\Usarrs\Auth\RegistrationRules;
use Marque\Usarrs\Contracts\OAuthProvider;
use Marque\Usarrs\Exceptions\InviteAlreadyRedeemed;
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
        // Anything but a string (?invite[]=x) is no invite, not a TypeError.
        $invite = $request->query('invite');
        session()->put('usarrs.oauth.invite', is_string($invite) ? $invite : null);

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
        $inviteCode = session()->pull('usarrs.oauth.invite');

        if ($identity->email === null) {
            return $this->refuse(__(':provider didn\'t share an email address, so an account can\'t be made from it.', ['provider' => ucfirst($provider)]));
        }

        $holder = $this->userByEmail($identity->email);

        if ($holder !== null) {
            $this->sendConfirmation($holder, $identity);
        }

        $sentLink = __('If an account here uses that :provider account\'s email address, we\'ve sent it a link to connect them — check your email.', ['provider' => ucfirst($provider)]);

        // Where no account could be made, a known address and an unknown one
        // get the same answer. "Sent a link" for one and "registration is
        // closed" for the other told anybody which addresses have accounts.
        if (($refusal = $this->rules->refusal($inviteCode)) !== null) {
            return $this->refuse($refusal.' '.$sentLink);
        }

        if ($holder !== null) {
            return redirect()->route('login')->with('status', __('That :provider account isn\'t connected here yet.', ['provider' => ucfirst($provider)]).' '.$sentLink);
        }

        return $this->register($identity, $inviteCode);
    }

    /**
     * A new account for an identity nobody here has — only where /register
     * would allow one, under the same rules (Spec #142). The callback used to
     * create an account for anything it didn't recognise, under every mode,
     * and sign it in unverified.
     *
     * The account, the invite and the link commit together (Build #124
     * CP #763): an invite lost to a concurrent request, or the same identity
     * linked by one, leaves no account behind and no error page.
     */
    private function register(OAuthIdentity $identity, ?string $inviteCode): RedirectResponse
    {
        $invite = $this->rules->validInvite($inviteCode);

        try {
            $user = DB::transaction(function () use ($identity, $invite) {
                $user = $this->userModel()::create([
                    'name' => $identity->name ?? $identity->nickname ?? $identity->email,
                    'email' => $identity->email,
                    // Nobody knows it and nothing can reset it under socialite
                    // mode: this account is reached through its OAuth link only.
                    'password' => Hash::make(Str::random(64)),
                ]);

                if ($invite !== null) {
                    $this->invites->redeem($invite, $user);
                }

                SocialAccount::forceCreate([
                    'user_id' => $user->getKey(),
                    'provider' => $identity->provider,
                    'provider_user_id' => $identity->id,
                ]);

                return $user;
            });
        } catch (InviteAlreadyRedeemed) {
            return $this->refuse(__('That invite has already been used.'));
        } catch (UniqueConstraintViolationException) {
            return $this->refuse(__('That :provider account was connected moments ago — try signing in again.', ['provider' => ucfirst($identity->provider)]));
        }

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
     * The mail goes whether or not registration is open, so what the visitor
     * is told can't depend on it.
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

        // Except where nobody has proven the address: that connection may be a
        // squatter's, and confirming drops it (ConfirmOAuthLink::stripUnprovenAccount()).
        if ($alreadyHasOne && ! ConfirmOAuthLink::unproven($holder)) {
            return;
        }

        $label = $identity->label();

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

        // Nobody has proven this account's address, so it may be a squatter's;
        // another provider on it would be a way back in that survives the owner
        // taking it over (CP #776).
        if (ConfirmOAuthLink::unproven($user)) {
            return redirect()->route('profile.show')
                ->withErrors(['email' => __('Verify your email address before connecting :provider.', ['provider' => $provider])]);
        }

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

        try {
            // Its own transaction, so a failed insert rolls back to a savepoint
            // rather than aborting any transaction around it (PostgreSQL).
            DB::transaction(fn () => SocialAccount::forceCreate([
                'user_id' => $user->getAuthIdentifier(),
                'provider' => $identity->provider,
                'provider_user_id' => $identity->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Connected concurrently — by this account (a double submit) or another.
            return SocialAccount::resolve($identity->provider, $identity->id)?->user_id === $user->getAuthIdentifier()
                ? redirect()->route('profile.show')->with('status', __(':provider is already connected to your account.', ['provider' => $provider]))
                : redirect()->route('profile.show')->withErrors(['email' => __('That :provider account is already connected to a different account.', ['provider' => $provider])]);
        }

        return redirect()->route('profile.show')->with('status', __(':provider connected.', ['provider' => $provider]));
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    /**
     * The account using this address, ignoring case. PostgreSQL and SQLite
     * compare case-sensitively, so a provider reporting `Member@x` missed the
     * account `member@x` — a second account for one inbox, or a lockout.
     * lower() is the same function on all four engines.
     */
    private function userByEmail(string $email): ?Authenticatable
    {
        $model = $this->userModel();

        return $model::query()
            ->whereRaw('lower(email) = lower(?)', [$email])
            ->orderBy((new $model)->getKeyName())
            ->first();
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
