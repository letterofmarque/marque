<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

use Illuminate\Contracts\Auth\MustVerifyEmail;

/**
 * Has anyone proven this account's address? (#10879)
 *
 * Asked before an account may add a way in (passkey, two-factor, an OAuth
 * provider), mint an invite, or be issued an announce key. An account nobody
 * has proven the address of may be a squatter's, and anything it attaches
 * outlives the owner taking it back. Tracker admins want verified addresses
 * (Dan, 2026-10-01), so verified is the default; an app whose User doesn't
 * implement MustVerifyEmail has opted out of verification and is never asked.
 */
final class VerifiedAddress
{
    public static function missing(?object $user): bool
    {
        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }
}
