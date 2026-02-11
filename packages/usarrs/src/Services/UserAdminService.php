<?php

declare(strict_types=1);

namespace Marque\Usarrs\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Marque\Trove\Enums\Role;
use Marque\Usarrs\Enums\UserStatus;

class UserAdminService
{
    public function list(?string $search = null, ?string $role = null, ?string $status = null, int $perPage = 25): LengthAwarePaginator
    {
        $model = config('trove.user_model', 'App\\Models\\User');

        return $model::query()
            ->when($search, fn ($q) => $q->where(
                fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhereRaw('LOWER(email) LIKE ?', ['%'.strtolower($search).'%'])
            ))
            ->when($role, fn ($q) => $q->where('role', $role))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate($perPage);
    }

    public function ban(Authenticatable $user): void
    {
        $user->update(['status' => UserStatus::Banned->value]);
    }

    public function unban(Authenticatable $user): void
    {
        $user->update(['status' => UserStatus::Active->value]);
    }

    public function setRole(Authenticatable $user, Role $role): void
    {
        $user->update(['role' => $role->value]);
    }
}
