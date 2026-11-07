<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Contracts;

use Illuminate\Http\Request;
use Marque\Trove\Contracts\UserInterface;

/**
 * Reshapes a legacy announce or scrape request into the form the native
 * tracker accepts, before it is handled (Job #109, Spec #117).
 *
 * An endpoint in `bloodhound.endpoints` names one by class, under 'transform'.
 * It is resolved from the container, never from a file: the announce endpoint
 * is the most-probed, least-authenticated URL a tracker has, and a config value
 * naming code to run there would be remote code execution.
 *
 * It runs on every request to its endpoint, announce and scrape alike: a
 * legacy scrape URL has the same shape as its announce URL. It cannot stop a
 * request, only reshape it, so a broken transform produces a request the
 * normal checks refuse with a bencoded error, never a tracker that silently
 * stops answering.
 */
interface AnnounceTransform
{
    /**
     * The request the native flow should see instead.
     *
     * bloodhound reads info_hash and peer_id, which are binary, from the RAW
     * query string (QUERY_STRING), not the parsed query. A transform that
     * changes the query returns ReshapedRequest::withQueryString(), which keeps
     * both in step. The endpoint's own key source and pattern still apply to
     * the reshaped request.
     */
    public function apply(Request $request): Request;

    /**
     * The announcing user, found by the transform's own means, or null for
     * "not mine": the native lookup by announce key then runs as usual. Null
     * never means "create one".
     */
    public function resolveUser(Request $request): ?UserInterface;
}
