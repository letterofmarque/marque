<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

use Illuminate\Http\Request;
use Marque\Bloodhound\Contracts\AnnounceTransform;
use Marque\Bloodhound\Support\ReshapedRequest;
use Marque\Trove\Contracts\UserInterface;

/**
 * A reshaping transform, as a migration would write one: old links carry the
 * key in upper-case hex, and the keys copied into Marque are lower-case.
 *
 * Records every path it reshaped, so a test can tell announce from scrape.
 */
class LowercaseKeyTransform implements AnnounceTransform
{
    /** @var list<string> */
    public static array $applied = [];

    public function apply(Request $request): Request
    {
        static::$applied[] = $request->path();

        $pairs = [];

        foreach (explode('&', (string) $request->server('QUERY_STRING', '')) as $pair) {
            if (str_starts_with($pair, 'passkey=')) {
                $pair = strtolower($pair);
            }

            $pairs[] = $pair;
        }

        return ReshapedRequest::withQueryString($request, implode('&', $pairs));
    }

    public function resolveUser(Request $request): ?UserInterface
    {
        return null;
    }
}
