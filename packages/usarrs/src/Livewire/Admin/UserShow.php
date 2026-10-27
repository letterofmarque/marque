<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Marque\Trove\Contracts\UserInterface;
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
        $service->ban($this->resolveUser(), actor: auth()->user());
    }

    public function unban(UserAdminService $service): void
    {
        $service->unban($this->resolveUser(), actor: auth()->user());
    }

    public function setRole(string $role, UserAdminService $service): void
    {
        $role = Role::tryFrom($role);

        abort_if($role === null, 422);

        // The service checks UserPolicy against the actor: who may act on
        // whom, and that granting Admin or Owner is the owner's alone.
        $service->setRole($this->resolveUser(), $role, actor: auth()->user());
    }

    protected function resolveUser()
    {
        $model = config('trove.user_model', 'App\\Models\\User');

        return $model::findOrFail($this->userId);
    }

    public function render(): View
    {
        $targetUser = $this->resolveUser();

        return $this->usarrsView('usarrs::admin.show', [
            'targetUser' => $targetUser,
            'stats' => $targetUser instanceof UserInterface ? $this->tracker()?->statsFor($targetUser) : null,
            // Only the roles this actor may give this user, so nothing is
            // offered that would be refused.
            'roles' => array_values(array_filter(
                Role::cases(),
                fn (Role $role): bool => $role !== $targetUser->role && Gate::allows('setRole', [$targetUser, $role]),
            )),
            'statuses' => UserStatus::cases(),
        ])->title($targetUser->name);
    }
}
