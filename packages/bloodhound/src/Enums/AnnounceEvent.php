<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Enums;

/**
 * BitTorrent announce event types.
 */
enum AnnounceEvent: string
{
    case Started = 'started';
    case Completed = 'completed';
    case Stopped = 'stopped';
}
