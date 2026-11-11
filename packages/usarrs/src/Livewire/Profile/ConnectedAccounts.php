<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Marque\Usarrs\Auth\StepUp;
use Marque\Usarrs\Exceptions\LastWayIn;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Notifications\OAuthConnectionNotice;

/**
 * The member's connected OAuth providers, with connect and disconnect
 * (#10858). Nothing used to list or remove a connection, so one made by
 * mistake, or by a session that wasn't the member's, stayed for good.
 *
 * Connecting is a POST to socialite.connect, which asks for a confirmed
 * step-up. Disconnecting asks for one here, and never removes the last way
 * in: under socialite an account is reached only through its connections, and
 * a passkey where it has one.
 */
class ConnectedAccounts extends Component
{
    /**
     * The page this component sits on, recorded when it renders. Locked, so a
     * client can't point the post-confirmation redirect somewhere else.
     */
    #[Locked]
    public string $returnTo = '';

    public function mount(): void
    {
        $this->returnTo = url()->current();
    }

    public function disconnect(string $provider): void
    {
        $user = auth()->user();
        $connection = $this->connections()->firstWhere('provider', $provider);

        if ($connection === null) {
            return;
        }

        if (! StepUp::recent()) {
            session()->put('url.intended', $this->returnTo);
            $this->redirect(route('password.confirm'));

            return;
        }

        try {
            DB::transaction(function () use ($user, $connection) {
                // Two removals at once each counted two connections and deleted
                // one, leaving none (Job #355). Locking the member's row makes
                // this member's removals take turns; the count after deleting is
                // the one that decides, and a removal that would leave no way in
                // rolls back.
                $user::query()->whereKey($user->getKey())->lockForUpdate()->first();

                $connection->delete();

                if ($this->connections()->isEmpty() && ! $this->hasPasskey()) {
                    throw new LastWayIn;
                }
            });
        } catch (LastWayIn) {
            $this->addError('disconnect', __(':provider is your only way to sign in. Connect another first.', ['provider' => ucfirst($provider)]));

            return;
        }

        $user->notify(new OAuthConnectionNotice($provider, connected: false));

        session()->flash('status', __(':provider removed.', ['provider' => ucfirst($provider)]));
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    private function connections(): Collection
    {
        return SocialAccount::query()
            ->where('user_id', auth()->id())
            ->orderBy('provider')
            ->get();
    }

    private function hasPasskey(): bool
    {
        $user = auth()->user();

        return config('usarrs.passkeys.enabled', false)
            && method_exists($user, 'passkeys')
            && $user->passkeys()->exists();
    }

    public function render(): View
    {
        $connections = $this->connections();

        return $this->usarrsView('usarrs::profile.connected-accounts', [
            'connections' => $connections,
            'connectable' => Route::has('socialite.connect')
                ? array_values(array_diff(config('usarrs.socialite_providers', []), $connections->pluck('provider')->all()))
                : [],
        ]);
    }
}
