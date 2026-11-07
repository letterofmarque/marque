<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Tests;

use Illuminate\Http\Request;
use Marque\Bloodhound\Contracts\AnnounceTransform;
use Marque\Bloodhound\Support\ReshapedRequest;
use Marque\Trove\Contracts\UserInterface;

/**
 * A reshaping transform, as a migration would write one: old links carry the
 * key as `pk`, in upper-case hex, where the endpoint reads `passkey` and the
 * keys copied into Marque are lower-case. (Case alone wouldn't show what the
 * transform did on every engine: MySQL and MariaDB compare keys
 * case-insensitively under their default collation.)
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
            if (str_starts_with($pair, 'pk=')) {
                $pair = 'passkey='.strtolower(substr($pair, 3));
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
