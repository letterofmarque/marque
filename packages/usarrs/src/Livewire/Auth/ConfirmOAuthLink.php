<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
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

        if ($existing !== null && $existing->user_id !== $user->getKey()) {
            $this->addError('token', __('That :provider account is already connected to a different account.', ['provider' => $name]));

            return;
        }

        if ($existing === null) {
            $alreadyHasOne = SocialAccount::query()
                ->where('user_id', $user->getKey())
                ->where('provider', $this->provider)
                ->exists();

            if ($alreadyHasOne) {
                $this->addError('token', __('This account is already connected to a different :provider account.', ['provider' => $name]));

                return;
            }

            SocialAccount::forceCreate([
                'user_id' => $user->getKey(),
                'provider' => $this->provider,
                'provider_user_id' => $pending['provider_user_id'],
            ]);
        }

        $this->redirect(app(LoginCompletion::class)->begin($user, remember: true), navigate: false);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.confirm-oauth-link', [
            'pending' => Cache::get(self::CACHE_PREFIX.$this->token),
            'providerName' => ucfirst($this->provider),
        ]);
    }
}
