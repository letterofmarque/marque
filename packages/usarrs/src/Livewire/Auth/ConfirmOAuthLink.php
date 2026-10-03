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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Auth\VerifiedAddress;
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
 * Connecting also proves the inbox (CP #763, #776): an unverified account is
 * verified, and — if OAuth made it — stripped of what it gained before; see
 * stripUnprovenAccount().
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

        // Only an account that already holds an OAuth connection can be a
        // squatter's: OAuth is the only way one is made under socialite mode,
        // and since 8.1 every account it makes is connected as it's made.
        // Accounts from before 8.1 were made unverified with no stored
        // connection; their owners keep what they set up (CP #776).
        $strip = $unproven && SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->where('provider', '!=', $this->provider)->orWhere('provider_user_id', '!=', $pending['provider_user_id']))
            ->exists();

        if ($existing !== null && $existing->user_id !== $user->getKey()) {
            $this->addError('token', __('That :provider account is already connected to a different account.', ['provider' => $name]));

            return;
        }

        // An account being stripped loses its existing connection below, so
        // that doesn't stand in the way.
        if ($existing === null && ! $strip) {
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
            DB::transaction(function () use ($user, $pending, $existing, $unproven, $strip) {
                if ($strip) {
                    $this->stripUnprovenAccount($user, $pending['provider_user_id']);
                }

                if ($unproven) {
                    $user->markEmailAsVerified();
                    event(new Verified($user));
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
        return VerifiedAddress::missing($user);
    }

    /**
     * Following the emailed link is the first proof anyone has given that they
     * own this address. If the account was made by OAuth before that proof, it
     * may have been made by somebody else entirely — a provider that merely
     * reported this address — so drop every way in it gained before then
     * (Build #124 CP #763, #776).
     *
     * Whatever the squatter attached goes: another provider, a passkey,
     * two-factor (which would lock the owner out behind the squatter's codes),
     * a remembered sign-in. And their open session: the password hash rotates
     * — nobody knows or uses it under socialite mode — and `auth.session`
     * (on usarrs' own routes, and the README asks it of the app's) ends any
     * session holding the old one. Sessions only expire when idle, so without
     * this an active squatter kept the account indefinitely.
     *
     * The tracker announce key is deliberately left alone: rotating it makes the
     * owner re-download every torrent. An unproven account shouldn't be issued
     * one at all (#10879).
     */
    private function stripUnprovenAccount(Authenticatable $user, string $keepProviderUserId): void
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
        $user->forceFill([$user->getAuthPasswordName() => Hash::make(Str::random(64))])->save();
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.confirm-oauth-link', [
            'pending' => Cache::get(self::CACHE_PREFIX.$this->token),
            'providerName' => ucfirst($this->provider),
        ]);
    }
}
