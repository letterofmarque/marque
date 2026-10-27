<?php

declare(strict_types=1);

namespace Marque\Bosun\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Marque\Bosun\Models\StaffPresence;
use Marque\Bosun\Services\AuditLog;
use Marque\Bosun\Services\Roster;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreenRegistry;
use Marque\Usarrs\Services\UserAdminService;

/**
 * The owner's view of the staff: who holds which role, how much each has done
 * lately, when they were last on, and promoting or demoting them.
 *
 * Role changes go through usarrs' UserAdminService with the signed-in user as
 * the actor, so the owner rules apply exactly as everywhere else (only an
 * owner grants or removes Admin and Owner, nobody changes their own role, the
 * last owner stays) and every change is recorded with its reason.
 */
class Staff extends Component
{
    public ?int $changing = null;

    public string $role = '';

    public string $reason = '';

    public string $addEmail = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function startChange(int $userId): void
    {
        $this->authorizeAdmin();

        $this->changing = $userId;
        $this->role = '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['changing', 'role', 'reason', 'addEmail']);
        $this->resetErrorBag();
    }

    public function applyChange(UserAdminService $users): void
    {
        $this->authorizeAdmin();

        $member = $this->changing === null ? null : $this->userModel()::query()->find($this->changing);
        abort_if($member === null, 404);

        $this->change($users, $member);
    }

    /**
     * Put someone not yet on the staff into a staff role, found by address.
     */
    public function addToStaff(UserAdminService $users): void
    {
        $this->authorizeAdmin();

        $member = $this->userModel()::query()->where('email', trim($this->addEmail))->first();

        if ($member === null) {
            $this->addError('addEmail', __('No user has that address.'));

            return;
        }

        $this->change($users, $member);
    }

    public function render(Roster $roster, AuditLog $log): View
    {
        $this->authorizeAdmin();

        $members = $roster->members();
        $ids = $members->map(fn (Model $m): int => (int) $m->getKey())->all();
        $changing = $this->changing === null ? null : $this->userModel()::query()->find($this->changing);

        return view('bosun::livewire.staff', [
            'members' => $members,
            'roster' => $roster,
            'counts' => $log->counts($ids),
            'lastSeen' => StaffPresence::query()->whereIn('user_id', $ids)->pluck('last_seen_at', 'user_id')->all(),
            'changeable' => $members->mapWithKeys(fn (Model $m): array => [(int) $m->getKey() => Gate::allows('promote', $m)])->all(),
            'changingMember' => $changing,
            'offered' => $changing === null ? [] : $this->offeredFor($changing),
            'addable' => $this->addableRoles($roster),
            'auditRoute' => $this->auditRoute(),
        ]);
    }

    private function change(UserAdminService $users, Model $member): void
    {
        $this->reason = trim($this->reason);

        $this->validate([
            'role' => ['required', 'in:'.implode(',', array_map(fn (Role $r): string => $r->value, Role::cases()))],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => __('Say why: the reason is kept with the change.'),
        ]);

        // UserAdminService checks UserPolicy against the actor and refuses
        // (403) anything the owner rules don't allow, whatever was offered.
        $users->setRole($member, Role::from($this->role), actor: auth()->user(), reason: $this->reason);

        $this->reset(['changing', 'role', 'reason', 'addEmail']);
    }

    /**
     * The roles the signed-in user may give this member, highest first, not
     * counting the one they hold.
     *
     * @return list<Role>
     */
    private function offeredFor(Model $member): array
    {
        $current = (new Roster)->roleOf($member);

        $roles = array_values(array_filter(
            Role::cases(),
            fn (Role $role): bool => $role !== $current && Gate::allows('setRole', [$member, $role]),
        ));
        usort($roles, fn (Role $a, Role $b): int => $b->rank() <=> $a->rank());

        return $roles;
    }

    /**
     * The staff roles the signed-in user may give at all.
     *
     * @return list<Role>
     */
    private function addableRoles(Roster $roster): array
    {
        $user = auth()->user();

        return array_values(array_filter(
            $roster->staffRoles(),
            fn (Role $role): bool => ! $role->isAtLeast(Role::Admin) || $user?->isOwner(),
        ));
    }

    /**
     * The Audit log's route, when a panel serves it.
     */
    private function auditRoute(): ?string
    {
        $screen = app(AdminScreenRegistry::class)->find('bosun-audit-log');

        return $screen !== null && Route::has($screen->routeName()) ? $screen->routeName() : null;
    }

    private function userModel(): string
    {
        return config('trove.user_model', 'App\\Models\\User');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }
}
