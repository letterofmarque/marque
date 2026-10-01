<?php

declare(strict_types=1);

namespace Marque\Usarrs\Exceptions;

use RuntimeException;

/**
 * The invite was no longer pending when it came to be redeemed — used,
 * revoked or expired since it was checked, usually by a concurrent request.
 */
class InviteAlreadyRedeemed extends RuntimeException {}
