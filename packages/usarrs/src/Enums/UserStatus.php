<?php

declare(strict_types=1);

namespace Marque\Usarrs\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Banned = 'banned';
    case Disabled = 'disabled';
    case Pending = 'pending';

    public function canLogin(): bool
    {
        return $this === self::Active;
    }

    /**
     * Why this user may not sign in, or null if they may (#10857).
     *
     * Only the values usarrs defines as inactive refuse. usarrs adds the
     * `status` column only when the app has none, so an app's own column may
     * mean anything — "enabled", 1, its own enum — and treating everything but
     * "active" as a ban logged out every user of such an app on every request
     * (Job #141 review). A backed enum is read by its value; any other object
     * is not a status usarrs knows.
     */
    public static function refusalFor(object $user): ?string
    {
        $status = method_exists($user, 'getAttribute') ? $user->getAttribute('status') : null;

        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }

        if (! is_string($status)) {
            return null;
        }

        return match (self::tryFrom($status)) {
            self::Banned => __('This account has been banned.'),
            self::Disabled => __('This account has been disabled.'),
            self::Pending => __('This account is awaiting approval.'),
            default => null,
        };
    }
}
