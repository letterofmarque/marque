<?php

declare(strict_types=1);

namespace Marque\Trove\Contracts;

use Marque\Trove\Models\Torrent;

/**
 * Whether the tracker lets this user download this .torrent (#10732).
 *
 * Bound by a tracker that restricts downloading, as bloodhound does for a
 * member below its ratio rule. TorrentFileService::forDownload() asks it
 * before building anything, so every frontend that serves a download through
 * trove is gated in one place. Nothing bound: nobody is refused.
 *
 * A separate contract rather than a method on TorrentFilePolicyInterface,
 * because adding a method to an interface breaks everything that already
 * implements it.
 */
interface DownloadGateInterface
{
    /**
     * Null to allow. Otherwise why not, written for the user: what's wrong and
     * what to do. Null user is a guest.
     */
    public function refusalFor(?UserInterface $user, Torrent $torrent): ?string;
}
