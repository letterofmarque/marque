<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Passkey;
use Livewire\Attributes\Locked;
use Marque\Usarrs\Livewire\Component;

class PasskeyManagement extends Component
{
    /**
     * The page this component sits on, recorded when it renders. Locked, so a
     * client can't point the post-confirmation redirect somewhere else.
     */
    #[Locked]
    public string $returnTo = '';

    public function mount(): void
    {
        abort_unless(config('usarrs.passkeys.enabled', false), 403);

        $this->returnTo = url()->current();
    }

    public function delete(int $passkeyId): void
    {
        $passkey = Passkey::findOrFail($passkeyId);

        abort_unless($passkey->user_id === auth()->id(), 403);

        // The DELETE endpoint asks for a confirmed password; this button used
        // not to (Job #141 review).
        if (! $this->passwordRecentlyConfirmed()) {
            $this->requirePasswordConfirmation();

            return;
        }

        $passkey->delete();

        session()->flash('status', __('Passkey removed.'));
    }

    /**
     * Send the user to confirm their password and bring them back here. The
     * page's script calls this when adding a passkey answers 423.
     */
    public function requirePasswordConfirmation(): void
    {
        session()->put('url.intended', $this->returnTo);

        $this->redirect(route('password.confirm'));
    }

    private function passwordRecentlyConfirmed(): bool
    {
        return time() - (int) session('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::profile.passkey-management', [
            'passkeys' => auth()->user()->passkeys()->latest()->get(),
            'optionsUrl' => $this->endpoint('passkey.registration-options', 'user/passkeys/options'),
            'storeUrl' => $this->endpoint('passkey.store', 'user/passkeys'),
        ])->title(__('Passkeys'));
    }

    /**
     * usarrs' route when it registered the endpoints, otherwise laravel/passkeys'
     * own path. An app with manage_auth off registers them itself (#10883).
     */
    private function endpoint(string $name, string $path): string
    {
        return Route::has($name) ? route($name) : url($path);
    }
}
