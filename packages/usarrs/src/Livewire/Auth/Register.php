<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Enums\AuthDriver;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Services\InviteService;

#[Title('Register')]
class Register extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|unique:users,email')]
    public string $email = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public string $invite = '';

    public function mount(): void
    {
        $driver = AuthDriver::from(config('usarrs.auth_driver', 'password'));
        abort_unless($driver->supportsRegistration(), 404);

        $this->invite = request()->query('invite', '');
    }

    public function register(InviteService $inviteService): void
    {
        $this->validate();

        if (config('usarrs.invites.required', false)) {
            $invite = $inviteService->findByCode($this->invite);
            if (! $invite || ! $invite->isValid()) {
                $this->addError('invite', __('A valid invite code is required.'));

                return;
            }
        }

        $model = config('trove.user_model', 'App\\Models\\User');
        $user = $model::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        if (isset($invite) && $invite) {
            $inviteService->redeem($invite, $user);
        }

        Auth::login($user);
        session()->regenerate();

        $this->redirect(url('/'), navigate: true);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.register', [
            'inviteRequired' => config('usarrs.invites.required', false),
        ]);
    }
}
