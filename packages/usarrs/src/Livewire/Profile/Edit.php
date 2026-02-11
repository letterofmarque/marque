<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Livewire\Component;

class Edit extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('nullable|string|max:1000')]
    public string $bio = '';

    #[Validate('nullable|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->bio = $user->bio ?? '';
    }

    public function save(): void
    {
        $this->validate();

        $user = auth()->user();
        $data = [
            'name' => $this->name,
            'email' => $this->email,
            'bio' => $this->bio ?: null,
        ];

        if ($this->password) {
            $data['password'] = Hash::make($this->password);
        }

        $user->update($data);

        session()->flash('status', __('Profile updated.'));
        $this->redirect(route('profile.show'), navigate: true);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::profile.edit')
            ->title(__('Edit Profile'));
    }
}
