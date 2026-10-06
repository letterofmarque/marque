<?php

declare(strict_types=1);

namespace Marque\Trove\Exceptions;

use RuntimeException;

/**
 * The tracker has no announce URL for this user (on a private tracker, a
 * member with no announce key yet), so there's no working .torrent to give
 * them. Refusing beats handing out a file their client can't announce with.
 */
final class NoAnnounceUrl extends RuntimeException
{
    public function __construct(string $message = 'You don\'t have an announce key yet, so this torrent can\'t be downloaded for you. If you haven\'t verified your email address, do that first.')
    {
        parent::__construct($message);
    }
}
