<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

use Marque\Usarrs\Enums\AuthDriver;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Services\InviteService;

/**
 * May a new account be created, and with which invite? One answer, shared by
 * every way an account gets made — the /register form and the OAuth callback
 * (Spec #142).
 *
 * The OAuth callback used to create accounts under every mode, invites or not,
 * because it never asked. Asking here means it gets the same answer the form
 * does, and a change to the rules (job #10801, invite_only) lands in both.
 *
 * Whether the *password form* is open is a separate question —
 * AuthDriver::allowsPasswordRegistration() — since under socialite accounts are
 * created, just never through a form.
 */
class RegistrationRules
{
    public function __construct(private readonly InviteService $invites) {}

    /**
     * Why an account can't be created, or null if it can.
     */
    public function refusal(?string $inviteCode): ?string
    {
        $driver = AuthDriver::from(config('usarrs.auth_driver', 'password'));

        if (! $driver->supportsRegistration() && ! $driver->requiresInvite()) {
            return __('Registration is closed.');
        }

        if ($this->inviteRequired() && $this->validInvite($inviteCode) === null) {
            return __('A valid invite code is required.');
        }

        return null;
    }

    /**
     * Whether a new account needs an invite: because the operator said so, or
     * because the driver is invite_only.
     */
    public function inviteRequired(): bool
    {
        return config('usarrs.invites.required', false)
            || AuthDriver::from(config('usarrs.auth_driver', 'password'))->requiresInvite();
    }

    /**
     * The invite to redeem for a new account, if one was given and is usable.
     * Redeemed whenever it is, required or not — an invite that was used to
     * join is used up (#10801).
     */
    public function validInvite(?string $inviteCode): ?Invite
    {
        if ($inviteCode === null || $inviteCode === '') {
            return null;
        }

        $invite = $this->invites->findByCode($inviteCode);

        return $invite !== null && $invite->isValid() ? $invite : null;
    }
}
