<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Contracts;

use Marque\Bloodhound\Support\LocationQuestion;
use Marque\Bloodhound\Support\LocationVerdict;

/**
 * Whether an announce from a new place keeps the member within their
 * seeding or leeching location limit on a torrent.
 *
 * Asked on every announce but `stopped`, so keep it cheap: the default passes
 * at once a peer that hasn't moved or turned from seeding to leeching, and
 * locates a peer where its last announce came from (DefaultLocationJudge).
 * Bind your own to use better evidence of where a peer is, such as matching
 * networks by ASN from GeoIP data you hold.
 *
 * A verdict passes, refuses, or calls the announce ambiguous: a refusal that
 * may be one machine on two address families. What happens to an ambiguous
 * one is the operator's policy (`anti_cheat.ambiguous_locations`), not the
 * judge's.
 */
interface LocationJudge
{
    public function judge(LocationQuestion $question): LocationVerdict;
}
