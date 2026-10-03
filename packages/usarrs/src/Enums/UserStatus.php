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
     * A user with no status at all is never refused: usarrs' migration adds the
     * column only if the app doesn't already have one, and an app without it
     * has no notion of a ban. A status usarrs doesn't recognise is refused —
     * an app that invented "frozen" did not mean "let them in".
     */
    public static function refusalFor(object $user): ?string
    {
        $status = method_exists($user, 'getAttribute') ? $user->getAttribute('status') : null;

        if ($status === null || $status === '') {
            return null;
        }

        $status = $status instanceof self ? $status : self::tryFrom((string) $status);

        return match ($status) {
            self::Active => null,
            self::Banned => __('This account has been banned.'),
            self::Disabled => __('This account has been disabled.'),
            self::Pending => __('This account is awaiting approval.'),
            null => __('This account is not active.'),
        };
    }
}
