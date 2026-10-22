<?php

declare(strict_types=1);

namespace Marque\Trove\Enums;

/**
 * Where an unmet seeding obligation stands under a hit-and-run rule (Job #256).
 */
enum ObligationState: string
{
    /** Still being seeded, or not yet away long enough to be warned. */
    case Open = 'open';

    /** Away from it while short: a strike follows if they stay away. */
    case Prewarned = 'prewarned';

    /** A hit-and-run. It clears if they seed it to the requirement, and lapses at $until. */
    case Struck = 'struck';
}
