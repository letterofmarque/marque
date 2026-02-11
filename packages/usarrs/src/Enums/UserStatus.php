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
}
