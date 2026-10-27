<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Marque\Trove\Enums\Role;

/**
 * Creates the first admin — without anybody choosing a password.
 *
 * Seeded as the tracker's **owner** (trove 5): an admin who can also grant
 * Admin. Only an owner can, so a first account seeded as a plain admin would
 * leave an install where nobody can ever make another admin.
 *
 * A fresh install has no admin and no way to make one: `role` defaults to
 * 'user', and no package ships a command to promote anybody, so the first
 * person to register is a plain user and /admin is unreachable without a
 * manual UPDATE.
 *
 * The account is seeded with random bytes nobody ever sees, and the operator
 * is sent a password reset link. One email does three jobs:
 *
 *   1. Sets the password, through the real reset flow rather than a special
 *      install-time path that would then be the only untested way in.
 *   2. Proves the address is real, because a human had to receive mail at it.
 *   3. Proves the mailer works, at the one moment the operator is still sitting
 *      in front of the install and can fix it.
 *
 * Nothing sensitive is held in memory across the install, typed into a
 * terminal, or printed to a scrollback buffer.
 */
final class AdminSeeder
{
    /** An admin or owner already exists, and so does an owner: nothing to do. */
    public const EXISTS = 'exists';

    /** Admins exist but nobody is the owner, so nobody can grant Admin. */
    public const EXISTS_WITHOUT_OWNER = 'exists_without_owner';

    /** Someone already has the address, without being an admin. */
    public const EMAIL_TAKEN = 'email_taken';

    /** Created, and the owner. */
    public const CREATED = 'created';

    /** Created, but the role didn't stick: the account is not the owner. */
    public const ROLE_NOT_SET = 'role_not_set';

    /**
     * Create the owner account, unless an admin already exists or the address
     * is taken. Says what happened; FinishInstallCommand says it to the operator.
     *
     * @param  class-string<Model>  $users  the app's user model
     */
    public function seed(string $users, string $name, string $email): string
    {
        if ($this->adminExists(fn (): int => $users::query()->whereIn('role', $this->adminRoles())->count())) {
            return $this->ownerMissing(fn (): int => $users::query()->where('role', $this->role())->count())
                ? self::EXISTS_WITHOUT_OWNER
                : self::EXISTS;
        }

        if ($users::query()->where('email', $email)->exists()) {
            return self::EMAIL_TAKEN;
        }

        $owner = $users::query()->create($this->attributesFor($name, $email));

        // Assigned rather than mass-assigned: stock Laravel's User declares
        // #[Fillable] without `role`, so passing it to create() drops it
        // silently and yields an "admin" that is not one.
        $owner->role = $this->role();
        $owner->save();

        return $owner->fresh()?->isOwner() ? self::CREATED : self::ROLE_NOT_SET;
    }

    /**
     * @param  callable(): int  $count  how many admins already exist
     */
    public function adminExists(callable $count): bool
    {
        return $count() > 0;
    }

    /**
     * The first account at Admin or above, owner included.
     *
     * @param  class-string<Model>  $users
     */
    public function firstAdmin(string $users): ?Model
    {
        return $users::query()->whereIn('role', $this->adminRoles())->first();
    }

    /**
     * The role values that count as an admin: Admin and above, so an owner
     * counts. A lookup for 'admin' alone would miss the owner this seeds.
     *
     * @return list<string>
     */
    public function adminRoles(): array
    {
        return array_map(fn (Role $role): string => $role->value, array_values(Role::atLeast(Role::Admin)));
    }

    /**
     * Whether an install whose admins already exist still has nobody who can
     * grant Admin: one from before trove 5 had an Owner role.
     *
     * @param  callable(): int  $owners  how many owners exist
     */
    public function ownerMissing(callable $owners): bool
    {
        return $owners() === 0;
    }

    /**
     * @return string|null an error message, or null when the details are fine
     */
    public function validate(string $name, string $email): ?string
    {
        if (trim($name) === '') {
            return 'The admin needs a name.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return "[{$email}] does not look like an email address.";
        }

        return null;
    }

    /**
     * Deliberately WITHOUT `role`.
     *
     * Found 2026-09-14 against the real clean-room app: passing role here
     * produced an account with role='user'. Laravel 13's stock User model
     * declares #[Fillable] attributes and does not list `role`, so mass
     * assignment dropped it silently — an "admin" account that is not an
     * admin, which is worse than failing outright because nothing tells you.
     *
     * The role is assigned explicitly after creation instead. See role().
     *
     * @return array<string, string>
     */
    public function attributesFor(string $name, string $email): array
    {
        return [
            'name' => $name,
            'email' => $email,
            // Random, hashed, and discarded. The account is unreachable until
            // the reset link is used, which is the point: there is no interim
            // credential to leak, reuse or forget to change.
            'password' => Hash::make(bin2hex(random_bytes(32))),
        ];
    }

    /**
     * Set on the model after create(), never mass-assigned — see
     * attributesFor(). Trove casts this column to its Role enum, and the
     * string value is what that enum is backed by.
     */
    public function role(): string
    {
        return Role::Owner->value;
    }
}
