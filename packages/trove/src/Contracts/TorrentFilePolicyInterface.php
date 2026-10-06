<?php

declare(strict_types=1);

namespace Marque\Trove\Contracts;

use Marque\Trove\Enums\PrivateFlag;

/**
 * What the installed tracker needs from the .torrent files trove handles.
 *
 * Bound by a tracker package (bloodhound, hound). With nothing bound, trove
 * accepts any valid v1 torrent and serves the stored file unchanged.
 */
interface TorrentFilePolicyInterface
{
    /**
     * The rule uploads are checked against.
     */
    public function privateFlag(): PrivateFlag;

    /**
     * The announce URL to write into a download for this user, or null if
     * the tracker can't give them a working one (a private tracker's member
     * with no announce key yet). Null user is a guest.
     *
     * A credential on a private tracker: it goes only into that user's own
     * download.
     */
    public function announceUrlFor(?UserInterface $user): ?string;
}
