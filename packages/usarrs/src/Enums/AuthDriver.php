<?php

declare(strict_types=1);

namespace Marque\Usarrs\Enums;

enum AuthDriver: string
{
    case Password = 'password';
    case MagicLink = 'magic_link';
    case Socialite = 'socialite';
    case InviteOnly = 'invite_only';

    public function supportsRegistration(): bool
    {
        return match ($this) {
            self::InviteOnly => false,
            default => true,
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
