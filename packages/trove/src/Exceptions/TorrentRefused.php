<?php

declare(strict_types=1);

namespace Marque\Trove\Exceptions;

use InvalidArgumentException;

/**
 * An uploaded .torrent the tracker won't accept. The message is meant for the
 * uploader: it says what's wrong and what to change.
 */
final class TorrentRefused extends InvalidArgumentException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
