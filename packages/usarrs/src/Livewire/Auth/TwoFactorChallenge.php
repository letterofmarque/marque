<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Livewire\Component;
use PragmaRX\Google2FA\Google2FA;

#[Title('Two-Factor Challenge')]
class TwoFactorChallenge extends Component
{
    #[Validate('required|string')]
    public string $code = '';

    public string $recoveryCode = '';

    public function mount(): void
    {
        abort_unless(config('usarrs.two_factor.enabled', false), 403);
        abort_unless(session()->has('login.id'), 403);
    }

    public function challenge(Google2FA $engine): void
    {
        $this->validate();

        $user = $this->pendingUser();

        if (! $engine->verify($this->code, Fortify::currentEncrypter()->decrypt($user->two_factor_secret))) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ]);
        }

        $this->completeLogin($user);
    }

    public function challengeWithRecoveryCode(): void
    {
        $this->validate(['recoveryCode' => 'required|string']);

        $user = $this->pendingUser();
        $codes = $user->recoveryCodes();

        if (! in_array($this->recoveryCode, $codes, true)) {
            throw ValidationException::withMessages([
                'recoveryCode' => [__('The provided recovery code was invalid.')],
            ]);
        }

        $user->replaceRecoveryCode($this->recoveryCode);

        $this->completeLogin($user);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.two-factor-challenge');
    }

    private function pendingUser(): mixed
    {
        $model = config('trove.user_model', 'App\\Models\\User');

        $user = $model::find(session('login.id'));

        abort_unless($user, 403);

        return $user;
    }

    private function completeLogin(mixed $user): void
    {
        Auth::login($user, session('login.remember', false));

        session()->forget(['login.id', 'login.remember']);
        session()->regenerate();

        $this->redirect(url('/'), navigate: true);
    }
}
