<?php

declare(strict_types=1);

namespace Marque\Trove\Enums;

/**
 * What a tracker accepts in an uploaded torrent's `private` flag.
 *
 * The flag lives inside the info dictionary, so changing it changes the
 * info_hash. Marque never rewrites it: an upload that breaks the rule is
 * refused with instructions, and the uploader recreates the torrent.
 * Without the flag, clients share peers over DHT and PEX, so a private
 * tracker's swarm leaks; with it on a public tracker, they don't, and the
 * swarm is smaller than it needs to be.
 */
enum PrivateFlag: string
{
    /** Accept either, silently. */
    case Allow = 'allow';

    /** Accept either; warn when the torrent is NOT private. */
    case WarnIfPublic = 'warn_if_public';

    /** Accept either; warn when the torrent IS private. */
    case WarnIfPrivate = 'warn_if_private';

    /** Refuse a torrent that is not private. A private tracker's default. */
    case Require = 'require';

    /** Refuse a private torrent. A public tracker's default. */
    case Disallow = 'disallow';
}
