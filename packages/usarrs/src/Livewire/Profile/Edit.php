<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Validate;
use Marque\Usarrs\Listeners\StampSessionPasswordHash;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Rules\UniqueEmail;

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
            'email' => ['required', 'email', new UniqueEmail(ignoreId: auth()->id())],
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

        // A new address is unproven, whatever the old one was. Keeping the
        // verification let a squatter verify their own inbox and switch back
        // to someone else's address as "verified" (Build #124 CP #776).
        $changed = mb_strtolower($this->email) !== mb_strtolower((string) $user->email);

        if ($this->password) {
            $data['password'] = Hash::make($this->password);
        }

        $user->update($data);

        if ($changed && $user instanceof MustVerifyEmail) {
            $user->forceFill(['email_verified_at' => null])->save();
            $user->sendEmailVerificationNotification();
        }

        // Then through the guard (which checks it against the hash just saved):
        // it re-issues this device's remember-me cookie under the new hash, and
        // other sessions end at their next request under auth.session. This
        // session records the new hash, or it would be the one signed out
        // (CP #777).
        if ($this->password) {
            Auth::logoutOtherDevices($this->password);
            StampSessionPasswordHash::stamp($user, Auth::getDefaultDriver());
        }

        session()->flash('status', __('Profile updated.'));
        $this->redirect(route('profile.show'), navigate: true);
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::profile.edit')
            ->title(__('Edit Profile'));
    }
}
