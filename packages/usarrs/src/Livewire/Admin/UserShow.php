<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Marque\Trove\Enums\Role;
use Marque\Usarrs\Enums\UserStatus;
use Marque\Usarrs\Livewire\Component;
use Marque\Usarrs\Services\UserAdminService;

class UserShow extends Component
{
    use AuthorizesRequests;

    public int $userId;

    public function mount(int $user): void
    {
        abort_unless(config('usarrs.admin.enabled', true), 404);
        abort_unless(auth()->user()->isModerator(), 403);

        $this->userId = $user;
    }

    public function ban(UserAdminService $service): void
    {
        $user = $this->resolveUser();
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_if(auth()->id() === $user->getAuthIdentifier(), 403);

        $service->ban($user);
    }

    public function unban(UserAdminService $service): void
    {
        $user = $this->resolveUser();
        abort_unless(auth()->user()->isAdmin(), 403);

        $service->unban($user);
    }

    public function setRole(string $role, UserAdminService $service): void
    {
        $user = $this->resolveUser();
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_if(auth()->id() === $user->getAuthIdentifier(), 403);

        $service->setRole($user, Role::from($role));
    }

    protected function resolveUser()
    {
        $model = config('trove.user_model', 'App\\Models\\User');

        return $model::findOrFail($this->userId);
    }

    public function render(): View
    {
        $targetUser = $this->resolveUser();
        $hasTrackerStats = method_exists($targetUser, 'getRatio');

        return $this->usarrsView('usarrs::admin.show', [
            'targetUser' => $targetUser,
            'hasTrackerStats' => $hasTrackerStats,
            'roles' => Role::cases(),
            'statuses' => UserStatus::cases(),
        ])->title($targetUser->name);
    }
}
