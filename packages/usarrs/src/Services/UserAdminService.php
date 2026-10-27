<?php

declare(strict_types=1);

namespace Marque\Usarrs\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Marque\Trove\Enums\Role;
use Marque\Usarrs\Enums\UserStatus;

/**
 * Banning members and changing their roles.
 *
 * Pass the acting staff member as `actor` and UserPolicy's rules apply: who
 * may act on whom, and who may grant Admin or Owner. With no actor (app code,
 * the console) they don't, because whoever calls without one is already
 * trusted. The last owner can't be demoted either way.
 */
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

    public function ban(Authenticatable $user, ?Authenticatable $actor = null): void
    {
        $this->authorize($actor, 'ban', [$user]);

        $user->update(['status' => UserStatus::Banned->value]);
    }

    public function unban(Authenticatable $user, ?Authenticatable $actor = null): void
    {
        $this->authorize($actor, 'unban', [$user]);

        $user->update(['status' => UserStatus::Active->value]);
    }

    /**
     * @throws AuthorizationException when the actor may not, or it would leave no owner
     */
    public function setRole(Authenticatable $user, Role $role, ?Authenticatable $actor = null): void
    {
        $this->authorize($actor, 'setRole', [$user, $role]);

        DB::transaction(function () use ($user, $role): void {
            if ($user->role === Role::Owner && $role !== Role::Owner) {
                // The owner rows themselves are locked, so two owners
                // demoting each other at once can't both see the other still
                // standing and leave nobody. Rows, not a count() query:
                // PostgreSQL refuses FOR UPDATE on an aggregate.
                $owners = $user->newQuery()
                    ->where('role', Role::Owner->value)
                    ->lockForUpdate()
                    ->get([$user->getKeyName()]);

                if ($owners->count() <= 1) {
                    throw new AuthorizationException(
                        'This is the last owner. Make someone else an owner before changing this role.',
                    );
                }
            }

            $user->update(['role' => $role->value]);
        });
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected function authorize(?Authenticatable $actor, string $ability, array $arguments): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize($ability, $arguments);
        }
    }
}
