<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Auth\LoginCompletion;
use Marque\Usarrs\Enums\AuthDriver;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Notifications\MagicLinkNotification;

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

        if (! $driver->allowsPasswordLogin()) {
            // socialite mode: the form is hidden, and the server refuses too —
            // a hidden form is not a closed door (job #10802).
            $this->addError('email', __('Password sign-in is not available on this site.'));

            return;
        }

        $this->validate();

        // Laravel's standard: five a minute per email and IP (#10856). Checked
        // before the password is, so the sixth try is refused even when right.
        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
            $this->addError('email', __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]));

            return;
        }

        if (! Auth::validate(['email' => $this->email, 'password' => $this->password])) {
            RateLimiter::hit($this->throttleKey());
            $this->addError('email', __('These credentials do not match our records.'));

            return;
        }

        RateLimiter::clear($this->throttleKey());

        $model = config('trove.user_model', 'App\\Models\\User');
        $user = $model::where('email', $this->email)->first();

        $this->redirect(app(LoginCompletion::class)->begin($user, $this->remember), navigate: true);
    }

    private function throttleKey(): string
    {
        return 'usarrs.login:'.Str::transliterate(Str::lower($this->email)).'|'.request()->ip();
    }

    protected function sendMagicLink(): void
    {
        $this->validate(['email' => 'required|email']);

        $model = config('trove.user_model', 'App\\Models\\User');
        $user = $model::where('email', $this->email)->first();

        if ($user) {
            $token = app('auth.password.broker')->createToken($user);
            $url = url('/auth/magic-link/verify?token='.$token.'&email='.urlencode($this->email));

            $user->notify(new MagicLinkNotification($url));
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
