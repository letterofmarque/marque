<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Auth\StepUp;
use Marque\Usarrs\Auth\VerifiedAddress;
use Marque\Usarrs\Livewire\Component;

class TwoFactorSetup extends Component
{
    #[Validate('required|string')]
    public string $code = '';

    /**
     * The page this component sits on, recorded when it renders. Locked, so a
     * client can't point the post-confirmation redirect somewhere else.
     */
    #[Locked]
    public string $returnTo = '';

    public function mount(): void
    {
        abort_unless(config('usarrs.two_factor.enabled', false), 403);
        abort_unless($this->userSupportsTwoFactor(), 404);

        $this->returnTo = url()->current();
    }

    public function enable(EnableTwoFactorAuthentication $action): void
    {
        if ($this->stepUpFirst() || $this->addressUnproven()) {
            return;
        }

        $action(auth()->user());
    }

    public function confirm(ConfirmTwoFactorAuthentication $action): void
    {
        if ($this->stepUpFirst() || $this->addressUnproven()) {
            return;
        }

        $this->validate();

        $action(auth()->user(), $this->code);

        $this->code = '';
        session()->flash('status', __('Two-factor authentication confirmed.'));
    }

    public function disable(DisableTwoFactorAuthentication $action): void
    {
        if ($this->stepUpFirst()) {
            return;
        }

        $action(auth()->user());

        session()->flash('status', __('Two-factor authentication disabled.'));
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $action): void
    {
        if ($this->stepUpFirst()) {
            return;
        }

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
            // Shown only to a session that confirmed recently: the codes are a
            // way in that skips the authenticator (Job #355).
            'recoveryCodes' => StepUp::recent() && ! empty($user->two_factor_confirmed_at) && ! empty($user->two_factor_recovery_codes) ? $user->recoveryCodes() : null,
        ])->title(__('Two-Factor Authentication'));
    }

    /**
     * Every change here takes a confirmed step-up (Job #355): a session that
     * isn't the member's could otherwise turn two-factor off, read new
     * recovery codes, or turn it on with its own authenticator and lock the
     * owner out behind a challenge only it can answer. Sends the member to
     * confirm and back; true when it did.
     */
    private function stepUpFirst(): bool
    {
        if (StepUp::recent()) {
            return false;
        }

        session()->put('url.intended', $this->returnTo);
        $this->redirect(route('password.confirm'));

        return true;
    }

    /**
     * Two-factor is a way in; an account nobody has proven the address of
     * doesn't get to add one (#10879).
     */
    private function addressUnproven(): bool
    {
        if (! VerifiedAddress::missing(auth()->user())) {
            return false;
        }

        $this->addError('email', __('Verify your email address before turning on two-factor authentication.'));

        return true;
    }

    private function userSupportsTwoFactor(): bool
    {
        $user = auth()->user();

        return $user && in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user), true);
    }
}
