<?php

declare(strict_types=1);

namespace Marque\Trove\Contracts;

use Marque\Trove\Support\TrackerSession;
use RuntimeException;

/**
 * A member's live sessions on the tracker, and a way for them to end one.
 *
 * A session is one client announcing one torrent. A client that restarts with
 * a new peer ID somewhere else leaves its old session live until it expires,
 * and while it does the old one counts against the member's location limits.
 * Ending it frees them at once, at the cost of whatever that session moved
 * since its last announce.
 *
 * Declared here and implemented by the tracker (bloodhound), the same split as
 * SeedboxesInterface: the sessions are the tracker's, showing them is the
 * frontend's. Ask the container first:
 *
 *     if (app()->bound(TrackerSessionsInterface::class)) { ... }
 */
interface TrackerSessionsInterface
{
    /**
     * The member's live sessions: leeching first, then the most recently
     * announced.
     *
     * @return list<TrackerSession>
     */
    public function sessionsFor(UserInterface $user): array;

    /**
     * End one of the member's own live sessions.
     *
     * Returns false when there is no such session of theirs: it already ended
     * or expired, the id is malformed, or it belongs to another member.
     *
     * @param  string  $sessionId  a TrackerSession's id, as sessionsFor() gave it
     *
     * @throws RuntimeException with a message fit to show the member, when the
     *                          session is busy with an announce and could not be ended
     */
    public function endSession(UserInterface $user, string $sessionId): bool;
}
