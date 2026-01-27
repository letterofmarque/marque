<?php

declare(strict_types=1);

namespace Marque\Trove\Policies;

use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Models\Torrent;

class TorrentPolicy
{
    /**
     * Determine whether the user can create torrents.
     *
     * Only uploaders and above can upload.
     */
    public function create(UserInterface $user): bool
    {
        return $user->isUploader();
    }

    /**
     * Determine whether the user can update the torrent.
     *
     * Owner can update their own torrents.
     * Moderators and admins can update any torrent.
     */
    public function update(UserInterface $user, Torrent $torrent): bool
    {
        return $user->getAuthIdentifier() === $torrent->user_id || $user->isModerator();
    }

    /**
     * Determine whether the user can delete the torrent.
     *
     * Only moderators and admins can delete torrents.
     */
    public function delete(UserInterface $user, Torrent $torrent): bool
    {
        return $user->isModerator();
    }
}
