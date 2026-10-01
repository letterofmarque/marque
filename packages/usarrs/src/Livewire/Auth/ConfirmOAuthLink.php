<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Models\SocialAccount;

/**
 * The page an emailed "connect your <provider> account?" link opens
 * (Spec #142, corrected by Build #124 CP #762).
 *
 * Opening it does nothing but say which provider account would be connected.
 * Connecting is a deliberate action on the page, and the link works once. The
 * first version connected on the GET itself and named nothing, so a mail
 * scanner following links — or one unwary click — connected an attacker's
 * identity to the inbox owner's account.
 *
 * The emailed URL is signed and expiring; the `token` in it is the key to a
 * pending connection held server-side, which connect() consumes.
 *
 * Connecting also proves the inbox (CP #763): an unverified account is
 * verified, and stripped of what it gained before — see proveInbox().
 */
#[Title('Connect account')]
class ConfirmOAuthLink extends Component
{
    public const CACHE_PREFIX = 'usarrs.oauth-link.';

    #[Locked]
    public string $provider = '';

    #[Locked]
    public string $token = '';

    public function mount(string $provider, string $token): void
    {
        abort_unless(in_array($provider, config('usarrs.socialite_providers', []), true), 404);

        $this->provider = $provider;
        $this->token = $token;
    }

    public function connect(): void
    {
        // pull: the pending connection is consumed whether or not it succeeds,
        // so the link can never be used twice.
        $pending = Cache::pull(self::CACHE_PREFIX.$this->token);

        if (! is_array($pending) || $pending['provider'] !== $this->provider) {
            $this->addError('token', __('This link has already been used or has expired.'));

            return;
        }

        $user = config('trove.user_model', 'App\\Models\\User')::find($pending['user']);
        if ($user === null) {
            $this->addError('token', __('This link has already been used or has expired.'));

            return;
        }

        $existing = SocialAccount::resolve($this->provider, $pending['provider_user_id']);
        $name = ucfirst($this->provider);
        $unproven = self::unproven($user);

        if ($existing !== null && $existing->user_id !== $user->getKey()) {
            $this->addError('token', __('That :provider account is already connected to a different account.', ['provider' => $name]));

            return;
        }

        // An unproven account's existing connection is dropped below, so it
        // doesn't stand in the way.
        if ($existing === null && ! $unproven) {
            $alreadyHasOne = SocialAccount::query()
                ->where('user_id', $user->getKey())
                ->where('provider', $this->provider)
                ->exists();

            if ($alreadyHasOne) {
                $this->addError('token', __('This account is already connected to a different :provider account.', ['provider' => $name]));

                return;
            }
        }

        try {
            DB::transaction(function () use ($user, $pending, $existing, $unproven) {
                if ($unproven) {
                    $this->proveInbox($user, $pending['provider_user_id']);
                }

                if ($existing === null) {
                    SocialAccount::forceCreate([
                        'user_id' => $user->getKey(),
                        'provider' => $this->provider,
                        'provider_user_id' => $pending['provider_user_id'],
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Connected concurrently — a second tab, a double click.
            $this->addError('token', __('That :provider account was connected moments ago — try signing in with it.', ['provider' => $name]));

            return;
        }

        $this->redirect(app(LoginCompletion::class)->begin($user, remember: true), navigate: false);
    }

    /**
     * Has nobody proven they own this account's address? Only an account that
     * can be verified can say; one whose model doesn't implement
     * MustVerifyEmail is taken as proven, having no other answer.
     */
    public static function unproven(Authenticatable $user): bool
    {
        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    /**
     * Following the emailed link is the first proof anyone has given that they
     * own this address — so verify it, and drop every way in the account gained
     * before that (Build #124 CP #763).
     *
     * The account may have been made by somebody else entirely: OAuth with a
     * provider that merely reported this address. Whatever they attached —
     * another provider, a passkey, two-factor (which would lock the owner out
     * behind the squatter's codes), a remembered sign-in — went on before
     * anybody proved the inbox, so none of it is the owner's.
     *
     * Not reached: a session the squatter has open right now. Laravel can only
     * end other sessions by password, and this account has none anyone knows.
     * It ends when the session expires.
     */
    private function proveInbox(Authenticatable $user, string $keepProviderUserId): void
    {
        SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->where('provider', '!=', $this->provider)->orWhere('provider_user_id', '!=', $keepProviderUserId))
            ->delete();

        if (method_exists($user, 'passkeys')) {
            $user->passkeys()->delete();
        }

        app(DisableTwoFactorAuthentication::class)($user);

        $user->setRememberToken(Str::random(60));
        $user->markEmailAsVerified();

        event(new Verified($user));
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.confirm-oauth-link', [
            'pending' => Cache::get(self::CACHE_PREFIX.$this->token),
            'providerName' => ucfirst($this->provider),
        ]);
    }
}
