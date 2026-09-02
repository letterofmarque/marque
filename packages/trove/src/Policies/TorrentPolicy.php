<?php

declare(strict_types=1);

namespace Marque\Trove\Policies;

use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Models\Torrent;

class TorrentPolicy
{
    /**
     * Determine whether the user can see the torrent listing at all.
     *
     * Everyone can — what they see *in* it is filtered by the visibleTo scope
     * on the query, not by this. A policy cannot filter a collection.
     */
    public function viewAny(?UserInterface $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view this torrent.
     *
     * Guests ($user === null, on the public frontend) see unrestricted
     * torrents only. This covers detail pages and .torrent downloads —
     * downloading is where the announce key is handed over, so it must not be
     * a weaker check than viewing.
     */
    public function view(?UserInterface $user, Torrent $torrent): bool
    {
        if ($torrent->min_role === null) {
            return true;
        }

        return $user !== null && $user->hasRoleAtLeast($torrent->min_role);
    }

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
