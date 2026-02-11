<?php

declare(strict_types=1);

namespace Marque\Usarrs\Enums;

enum InviteStatus: string
{
    case Pending = 'pending';
    case Used = 'used';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
