<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The announce URL's shape, read from config in exactly one place.
 *
 * The key format used to be asserted in four places — the announce route
 * constraint, the scrape route constraint, and a preg_match in each of the two
 * controllers — all four spelling out `[0-9a-zA-Z]{32}` independently. That was
 * survivable while the pattern was hardcoded and identical. It stops being
 * survivable the moment it is configurable: a consumer setting a wider pattern
 * would widen the route constraint and leave the controllers rejecting what the
 * router just accepted, or the reverse, depending on which copy was missed.
 *
 * So every question about the announce URL is answered here.
 *
 * Since Job #109 a tracker serves several announce URLs at once: the primary
 * endpoint (`bloodhound.routes`) and any number of others
 * (`bloodhound.endpoints`), each an AnnounceEndpoint. endpoints() is the list
 * the router registers, and forRequest() says which one a request came in on.
 * The static questions below are about the primary endpoint, as they were when
 * it was the only one, so nothing calling them changes.
 */
final class AnnounceRouting
{
    /**
     * The primary endpoint's name: `bloodhound.routes`. Its routes are named
     * tracker.announce and tracker.scrape, and .torrent downloads carry it.
     */
    public const PRIMARY = 'primary';

    /**
     * The route action key naming the endpoint a route belongs to. On the
     * action, not a route default: the controllers take the key positionally,
     * and an extra route parameter would shift it.
     */
    public const ACTION = 'bloodhound_endpoint';

    /**
     * Every endpoint, primary first, keyed by name.
     *
     * Refuses two endpoints sharing a path, either way round (one's announce
     * on another's scrape included), naming both. Laravel lets the first
     * registered route win without a word, and a silently shadowed endpoint is
     * a migration that looks configured and serves nothing.
     *
     * @return array<string, AnnounceEndpoint>
     */
    public static function endpoints(): array
    {
        $endpoints = [self::PRIMARY => self::primary()];

        foreach ((array) config('bloodhound.endpoints', []) as $name => $config) {
            $name = (string) $name;

            if (preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
                throw new InvalidArgumentException("bloodhound.endpoints: \"{$name}\" can't name an endpoint. Use lowercase letters, digits, - and _: it becomes part of the route names.");
            }

            if ($name === self::PRIMARY) {
                throw new InvalidArgumentException('bloodhound.endpoints: "'.self::PRIMARY.'" is the endpoint bloodhound.routes describes. Name this one something else.');
            }

            $endpoints[$name] = AnnounceEndpoint::fromConfig($name, is_array($config) ? $config : []);
        }

        $claimed = [];

        foreach ($endpoints as $endpoint) {
            foreach (['announce' => $endpoint->announcePath, 'scrape' => $endpoint->scrapePath] as $role => $path) {
                if (isset($claimed[$path])) {
                    throw new InvalidArgumentException(sprintf(
                        'Announce endpoints collide on the path "%s": the %s endpoint\'s %s and the %s endpoint\'s %s. Only one would ever answer, so give each its own path.',
                        $path, $claimed[$path][0], $claimed[$path][1], $endpoint->name, $role,
                    ));
                }

                $claimed[$path] = [$endpoint->name, $role];
            }
        }

        return $endpoints;
    }

    /**
     * The primary endpoint, from bloodhound.routes.
     */
    public static function primary(): AnnounceEndpoint
    {
        return AnnounceEndpoint::fromConfig(self::PRIMARY, (array) config('bloodhound.routes', []));
    }

    /**
     * The endpoint a request came in on, from its route's action. The primary
     * when the route names none, which is every route registered before
     * Job #109 and every request made outside the router.
     */
    public static function forRequest(Request $request): AnnounceEndpoint
    {
        $name = $request->route()?->getAction(self::ACTION);

        if (! is_string($name) || $name === self::PRIMARY) {
            return self::primary();
        }

        return self::endpoints()[$name] ?? self::primary();
    }

    /**
     * The route names for an endpoint: tracker.announce and tracker.scrape for
     * the primary, so consumers' route() calls survive any config, and
     * tracker.announce.<name> / tracker.scrape.<name> for the others.
     *
     * @return array{announce: string, scrape: string}
     */
    public static function routeNames(AnnounceEndpoint $endpoint): array
    {
        $suffix = $endpoint->name === self::PRIMARY ? '' : '.'.$endpoint->name;

        return ['announce' => 'tracker.announce'.$suffix, 'scrape' => 'tracker.scrape'.$suffix];
    }

    /**
     * Where the primary endpoint takes the announce key from: 'path' or 'query'.
     *
     * Anything other than 'query' is treated as 'path'. A typo in this key
     * should not silently produce a keyless tracker.
     */
    public static function keySource(): string
    {
        return self::primary()->keySource;
    }

    public static function keyIsInQuery(): bool
    {
        return self::primary()->keyIsInQuery();
    }

    /**
     * The primary endpoint's route segment name, or query parameter name.
     */
    public static function keyParameter(): string
    {
        return self::primary()->keyParameter;
    }

    /**
     * The primary endpoint's raw pattern, as written in config: no delimiters,
     * no anchors. This is what a Laravel route constraint wants. Callers
     * matching a string themselves want matches() instead, which anchors it.
     */
    public static function keyPattern(): string
    {
        return self::primary()->keyPattern;
    }

    /**
     * Does this string match the primary endpoint's key format? Anchored; see
     * AnnounceEndpoint::matches().
     */
    public static function matches(string $key): bool
    {
        return self::primary()->matches($key);
    }

    /**
     * Pull the announce key out of a request the way the primary endpoint
     * carries it. A controller asks forRequest() instead, for the endpoint the
     * request actually came in on.
     */
    public static function keyFromRequest(Request $request, ?string $routeKey = null): ?string
    {
        return self::primary()->keyFromRequest($request, $routeKey);
    }

    /**
     * How the primary endpoint answers a malformed key in the path: 'failure'
     * (a bencoded failure reason, the default from 9.0) or 'not_found' (the
     * router's 404, the default before it).
     */
    public static function malformedKeyAnswer(): string
    {
        return self::primary()->malformedKey;
    }

    public static function announcePath(): string
    {
        return self::primary()->announcePath;
    }

    public static function scrapePath(): string
    {
        return self::primary()->scrapePath;
    }
}
