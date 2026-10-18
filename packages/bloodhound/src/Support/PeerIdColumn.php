<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

/**
 * A peer ID as the ledger stores it.
 *
 * BEP 3 lets a peer ID be any 20 bytes, and the ledger's text column cannot
 * hold them all: MySQL, MariaDB and PostgreSQL refuse bytes that are not
 * UTF-8, and PostgreSQL ends a value at a NUL. So a peer ID that is valid
 * UTF-8 with no NUL (every peer ID the ledger has held so far) is stored as
 * it is, and any other is stored as `x:` and its 40 hex digits.
 *
 * Deterministic, so every row of one peer stores the same value and lookups
 * by peer ID still match. It cannot collide: a raw peer ID is 20 bytes and an
 * encoded one 42.
 */
final class PeerIdColumn
{
    public static function encode(string $peerId): string
    {
        return mb_check_encoding($peerId, 'UTF-8') && ! str_contains($peerId, "\0")
            ? $peerId
            : 'x:'.bin2hex($peerId);
    }
}
