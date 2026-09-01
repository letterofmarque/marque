<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Enums\AuthDriver;
use Marque\Usarrs\Livewire\Component;

#[Title('Login')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $driver = AuthDriver::from(config('usarrs.auth_driver', 'password'));

        if ($driver === AuthDriver::MagicLink) {
            $this->sendMagicLink();

            return;
        }

        $this->validate();

        if (! Auth::validate(['email' => $this->email, 'password' => $this->password])) {
            $this->addError('email', __('These credentials do not match our records.'));

            return;
        }

        $model = config('trove.user_model', 'App\\Models\\User');
        $user = $model::where('email', $this->email)->first();

        if ($this->requiresTwoFactorChallenge($user)) {
            session()->put([
                'login.id' => $user->getAuthIdentifier(),
                'login.remember' => $this->remember,
            ]);

            $this->redirect(route('two-factor.login'), navigate: true);

            return;
        }

        Auth::login($user, $this->remember);
        session()->regenerate();

        $this->redirect(url('/'), navigate: true);
    }

    private function requiresTwoFactorChallenge(mixed $user): bool
    {
        if (! config('usarrs.two_factor.enabled', false)) {
            return false;
        }

        if (! $user || ! in_array(\Laravel\Fortify\TwoFactorAuthenticatable::class, class_uses_recursive($user), true)) {
            return false;
        }

        // Deliberately not Fortify's own hasEnabledTwoFactorAuthentication():
        // that method's "enabled" vs "confirmed" distinction is gated behind
        // Fortify's own features config, which usarrs never populates (usarrs
        // never calls Fortify::routes() or configures config/fortify.php).
        // Checking two_factor_confirmed_at directly is unambiguous regardless
        // of Fortify's feature flags, and is what TwoFactorSetup itself uses
        // to decide "enabled but not yet confirmed" vs "confirmed".
        return ! empty($user->two_factor_secret) && ! empty($user->two_factor_confirmed_at);
    }

    protected function sendMagicLink(): void
    {
        $this->validate(['email' => 'required|email']);

        $model = config('trove.user_model', 'App\\Models\\User');
        $user = $model::where('email', $this->email)->first();

        if ($user) {
            $token = app('auth.password.broker')->createToken($user);
            $url = url('/auth/magic-link/verify?token='.$token.'&email='.urlencode($this->email));

            $user->notify(new \Marque\Usarrs\Notifications\MagicLinkNotification($url));
        }

        session()->flash('status', __('If an account exists, a login link has been sent.'));
        $this->redirect(route('login'), navigate: true);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.login', [
            'driver' => AuthDriver::from(config('usarrs.auth_driver', 'password')),
        ]);
    }
}
