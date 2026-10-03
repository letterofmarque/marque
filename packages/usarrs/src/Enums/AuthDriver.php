<?php

declare(strict_types=1);

namespace Marque\Usarrs\Enums;

enum AuthDriver: string
{
    case Password = 'password';
    case MagicLink = 'magic_link';
    case Socialite = 'socialite';
    case InviteOnly = 'invite_only';

    /**
     * Whether anyone may sign up, invite or not — what the "Register" link on
     * the login page means. Under invite_only the answer is no, but an invite
     * still opens the door: see requiresInvite().
     */
    public function supportsRegistration(): bool
    {
        return match ($this) {
            self::InviteOnly => false,
            default => true,
        };
    }

    /**
     * Whether a new account needs a valid invite whatever invites.required
     * says. invite_only used to close registration outright, invites included,
     * so no account could be made by any route (#10801).
     */
    public function requiresInvite(): bool
    {
        return $this === self::InviteOnly;
    }

    /**
     * Whether the password registration form is open to everyone. Not the same question
     * as supportsRegistration(): under socialite, accounts are created — by
     * OAuth — but never through a password form (Spec #142).
     */
    public function allowsPasswordRegistration(): bool
    {
        return match ($this) {
            self::Password, self::MagicLink => true,
            default => false,
        };
    }

    /**
     * Whether an email + password login is accepted. Enforced on the server:
     * hiding the form is not the same thing, and socialite mode used to do
     * only that (job #10802).
     */
    public function allowsPasswordLogin(): bool
    {
        return match ($this) {
            self::Password, self::InviteOnly => true,
            default => false,
        };
    }

    public function supportsPasswordReset(): bool
    {
        return match ($this) {
            self::Password, self::InviteOnly => true,
            default => false,
        };
    }

    public function requiresPassword(): bool
    {
        return match ($this) {
            self::Password, self::InviteOnly => true,
            default => false,
        };
    }
}
