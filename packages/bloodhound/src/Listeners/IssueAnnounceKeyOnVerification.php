<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Listeners;

use Illuminate\Auth\Events\Verified;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Contracts\UserInterface;

/**
 * Issues the announce key that HasTrackerStats held back from an unverified
 * sign-up, once the address is proven (usarrs #10879).
 *
 * Only ever issues: a user who already has a key keeps it, because rotating
 * one makes them re-download every .torrent they run.
 */
class IssueAnnounceKeyOnVerification
{
    public function __construct(private readonly TrackerStatsInterface $tracker) {}

    public function handle(Verified $event): void
    {
        $user = $event->user;

        if ($user instanceof UserInterface && $this->tracker->announceKeyFor($user) === null) {
            $this->tracker->regenerateAnnounceKey($user);
        }
    }
}
