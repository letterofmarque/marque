<?php

declare(strict_types=1);

namespace Marque\Trove\Enums;

/**
 * Where a member stands against the tracker's ratio rule (#10732).
 */
enum StandingState: string
{
    /** Nothing to say: within the rule, or no rule applies. */
    case Ok = 'ok';

    /** Below the rule, with a deadline to recover by before restriction. */
    case Watch = 'watch';

    /** New downloading is paused until the member recovers. Seeding never is. */
    case Restricted = 'restricted';

    /** The rule doesn't apply to this member: staff, or exempted by an admin. */
    case Exempt = 'exempt';
}
