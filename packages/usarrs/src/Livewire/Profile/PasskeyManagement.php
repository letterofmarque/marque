<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Marque\Usarrs\Livewire\Component;

class PasskeyManagement extends Component
{
    public function regeneratePasskey(): void
    {
        $user = auth()->user();

        abort_unless(config('usarrs.profile.allow_passkey_regen', true), 403);
        abort_unless(method_exists($user, 'generatePasskey'), 404);

        $user->update(['passkey' => $user->generatePasskey()]);

        session()->flash('status', __('Passkey regenerated.'));
    }

    public function render(): View
    {
        $user = auth()->user();

        return $this->usarrsView('usarrs::profile.stats', [
            'user' => $user,
            'hasTrackerStats' => method_exists($user, 'getRatio'),
            'showPasskey' => config('usarrs.profile.show_passkey', true) && isset($user->passkey),
            'allowRegen' => config('usarrs.profile.allow_passkey_regen', true),
        ])->title(__('Tracker Stats'));
    }
}
