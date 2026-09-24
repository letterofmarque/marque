<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

use Marque\Bloodhound\Concerns\HasTrackerStats;

/**
 * A consumer User model that applies HasTrackerStats, the way twentyt's does.
 *
 * TestUser deliberately does not, so that most tests prove bloodhound works
 * against whatever user model the host app configured. This one exists for the
 * behaviour the trait adds on top — issuing a key when a user is created.
 */
class TrackerUser extends TestUser
{
    use HasTrackerStats;
}
