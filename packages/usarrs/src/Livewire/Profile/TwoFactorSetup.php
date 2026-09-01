<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Livewire\Component;

class TwoFactorSetup extends Component
{
    #[Validate('required|string')]
    public string $code = '';

    public function mount(): void
    {
        abort_unless(config('usarrs.two_factor.enabled', false), 403);
        abort_unless($this->userSupportsTwoFactor(), 404);
    }

    public function enable(EnableTwoFactorAuthentication $action): void
    {
        $action(auth()->user());
    }

    public function confirm(ConfirmTwoFactorAuthentication $action): void
    {
        $this->validate();

        $action(auth()->user(), $this->code);

        $this->code = '';
        session()->flash('status', __('Two-factor authentication confirmed.'));
    }

    public function disable(DisableTwoFactorAuthentication $action): void
    {
        $action(auth()->user());

        session()->flash('status', __('Two-factor authentication disabled.'));
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $action): void
    {
        $action(auth()->user());

        session()->flash('status', __('Recovery codes regenerated.'));
    }

    public function render(): View
    {
        $user = auth()->user();

        return $this->usarrsView('usarrs::profile.two-factor-setup', [
            'enabled' => ! empty($user->two_factor_secret),
            'confirmed' => ! empty($user->two_factor_confirmed_at),
            'qrCodeSvg' => ! empty($user->two_factor_secret) && empty($user->two_factor_confirmed_at) ? $user->twoFactorQrCodeSvg() : null,
            'recoveryCodes' => ! empty($user->two_factor_confirmed_at) && ! empty($user->two_factor_recovery_codes) ? $user->recoveryCodes() : null,
        ])->title(__('Two-Factor Authentication'));
    }

    private function userSupportsTwoFactor(): bool
    {
        $user = auth()->user();

        return $user && in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user), true);
    }
}
