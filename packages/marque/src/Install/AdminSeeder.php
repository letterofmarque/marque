<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use Illuminate\Support\Facades\Hash;

/**
 * Creates the first admin — without anybody choosing a password.
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
    /**
     * @param  callable(): int  $count  how many admins already exist
     */
    public function adminExists(callable $count): bool
    {
        return $count() > 0;
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
        return 'admin';
    }
}
