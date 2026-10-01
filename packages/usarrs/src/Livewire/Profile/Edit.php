<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Livewire\Component;

class Edit extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

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

    /**
     * The email rules need the user's id (unique, ignoring their own row), so
     * they live here rather than in an attribute.
     */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'email', Rule::unique($this->userTable(), 'email')->ignore(auth()->id())],
        ];
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

        // A new address is unproven, whatever the old one was. Keeping the
        // verification let a squatter verify their own inbox and switch back
        // to someone else's address as "verified" (Build #124 CP #776).
        $changed = mb_strtolower($this->email) !== mb_strtolower((string) $user->email);
        if ($changed && $user instanceof MustVerifyEmail) {
            $data['email_verified_at'] = null;
        }

        $user->forceFill($data)->save();

        if ($changed && $user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }

        session()->flash('status', __('Profile updated.'));
        $this->redirect(route('profile.show'), navigate: true);
    }

    private function userTable(): string
    {
        $model = config('trove.user_model', 'App\\Models\\User');

        return (new $model)->getTable();
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::profile.edit')
            ->title(__('Edit Profile'));
    }
}
