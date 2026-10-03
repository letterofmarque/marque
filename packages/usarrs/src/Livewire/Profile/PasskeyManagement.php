<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Passkey;
use Marque\Usarrs\Livewire\Component;

class PasskeyManagement extends Component
{
    public function mount(): void
    {
        abort_unless(config('usarrs.passkeys.enabled', false), 403);
    }

    public function delete(int $passkeyId): void
    {
        $passkey = Passkey::findOrFail($passkeyId);

        abort_unless($passkey->user_id === auth()->id(), 403);

        $passkey->delete();

        session()->flash('status', __('Passkey removed.'));
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
