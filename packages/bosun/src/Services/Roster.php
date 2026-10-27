<?php

declare(strict_types=1);

namespace Marque\Bosun\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Marque\Trove\Enums\Role;

/**
 * Who the staff are: everyone at bosun.staff_role (moderator by default) and
 * above, highest role first.
 */
class Roster
{
    /**
     * @return Collection<int, Model>
     */
    public function members(): Collection
    {
        $model = config('trove.user_model', 'App\\Models\\User');
        $roles = $this->staffRoles();

        return $model::query()
            ->whereIn('role', array_map(fn (Role $role): string => $role->value, $roles))
            ->orderBy('name')
            ->get()
            ->sortByDesc(fn (Model $user): int => $this->roleOf($user)->rank())
            ->values();
    }

    /**
     * The roles that count as staff, highest first.
     *
     * @return list<Role>
     */
    public function staffRoles(): array
    {
        $floor = Role::tryFrom((string) config('bosun.staff_role', 'moderator')) ?? Role::Moderator;

        $roles = array_values(Role::atLeast($floor));
        usort($roles, fn (Role $a, Role $b): int => $b->rank() <=> $a->rank());

        return $roles;
    }

    public function roleOf(Model $user): Role
    {
        $role = $user->getAttribute('role');

        return $role instanceof Role ? $role : (Role::tryFrom((string) $role) ?? Role::User);
    }
}
