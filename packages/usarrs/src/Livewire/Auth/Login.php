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

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            $this->addError('email', __('These credentials do not match our records.'));

            return;
        }

        session()->regenerate();

        $this->redirect(url('/'), navigate: true);
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
