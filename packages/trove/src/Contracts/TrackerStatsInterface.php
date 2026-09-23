<?php

declare(strict_types=1);

namespace Marque\Trove\Contracts;

use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\TorrentStats;
use Marque\Trove\Support\TrackerStats;

/**
 * A tracker's per-user figures, for packages that do not own them.
 *
 * Declared here and implemented by the tracker (bloodhound), the same split as
 * TorrentServiceInterface: the contract lives where both sides can see it, the
 * implementation lives with the owner of the data.
 *
 * **Ask the container whether this exists before asking it anything.** Nothing
 * binds it unless a tracker that keeps per-user figures is installed — a public
 * tracker or a catalogue-only install has none — so the capability check is:
 *
 *     $stats = app()->bound(TrackerStatsInterface::class)
 *         ? app(TrackerStatsInterface::class)->statsFor($user)
 *         : null;
 *
 * Not `method_exists($user, 'getRatio')`. That answers "is a trait applied to
 * the User model", which is a question about wiring, not about whether this
 * install tracks anything (Spec #119).
 *
 * The figures are what the tracker has stored, not what it enforces. Whether a
 * minimum ratio or seedtime is required is not part of this contract.
 */
interface TrackerStatsInterface
{
    /**
     * The user's totals across every torrent, or null if the tracker keeps no
     * figures for this user.
     */
    public function statsFor(UserInterface $user): ?TrackerStats;

    /**
     * What the user has done on one torrent, or null if they have never
     * announced it.
     */
    public function statsForTorrent(UserInterface $user, Torrent $torrent): ?TorrentStats;

    /**
     * The user's current announce key, or null if they do not have one.
     *
     * A credential: show it to its owner, never to anyone else.
     */
    public function announceKeyFor(UserInterface $user): ?string;

    /**
     * Replace the user's announce key and return the new one.
     *
     * The old key stops working immediately, so any torrent file carrying it
     * has to be downloaded again. The tracker is the only thing that writes
     * announce keys — callers go through here rather than setting a column.
     */
    public function regenerateAnnounceKey(UserInterface $user): string;
}
