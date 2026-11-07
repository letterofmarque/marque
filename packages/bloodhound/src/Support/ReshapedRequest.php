<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use Illuminate\Http\Request;

/**
 * Builds the request an AnnounceTransform returns (Job #109).
 *
 * bloodhound reads info_hash and peer_id from the raw query string, because
 * they are binary and PHP's parsing keeps only the last of a repeated key while
 * TrimStrings mangles binary values (#11057). A transform that rewrote only the
 * parsed query would leave the raw string saying something else, so the
 * reshaped request carries both, built from the one string.
 */
final class ReshapedRequest
{
    /**
     * A copy of the request whose query, parsed and raw, is $queryString.
     *
     * Everything else (headers, client address, the route it matched) is
     * kept. The original is not changed.
     */
    public static function withQueryString(Request $request, string $queryString): Request
    {
        parse_str($queryString, $query);

        $server = $request->server->all();
        $server['QUERY_STRING'] = $queryString;

        return $request->duplicate(query: $query, server: $server);
    }
}
