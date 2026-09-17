<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use Illuminate\Http\Request;

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
 */
final class AnnounceRouting
{
    /**
     * Where the announce key comes from: 'path' or 'query'.
     *
     * Anything other than 'query' is treated as 'path'. A typo in this key
     * should not silently produce a keyless tracker.
     */
    public static function keySource(): string
    {
        return config('bloodhound.routes.key_source') === 'query' ? 'query' : 'path';
    }

    public static function keyIsInQuery(): bool
    {
        return self::keySource() === 'query';
    }

    /**
     * The route segment name, or the query parameter name.
     */
    public static function keyParameter(): string
    {
        $name = (string) config('bloodhound.routes.key_parameter', 'announce_key');

        return $name !== '' ? $name : 'announce_key';
    }

    /**
     * The raw pattern, as written in config — no delimiters, no anchors.
     *
     * This is what a Laravel route constraint wants. Callers matching a string
     * themselves want matches() instead, which anchors it.
     */
    public static function keyPattern(): string
    {
        $pattern = (string) config('bloodhound.routes.key_pattern', '[0-9a-zA-Z]{32}');

        return $pattern !== '' ? $pattern : '[0-9a-zA-Z]{32}';
    }

    /**
     * Does this string match the configured key format?
     *
     * Anchored here rather than in config, so a consumer cannot accidentally
     * supply an unanchored pattern and have 'junk<validkey>junk' accepted.
     * The `D` modifier stops a trailing newline satisfying `$`.
     */
    public static function matches(string $key): bool
    {
        return preg_match('/^(?:'.self::keyPattern().')$/D', $key) === 1;
    }

    /**
     * Pull the announce key out of a request, wherever it is configured to be.
     *
     * Returns null when the key is absent entirely, which the caller must treat
     * as a failure rather than as an empty key: in query mode the parameter can
     * simply be missing, where in path mode the router would already have
     * refused the request.
     */
    public static function keyFromRequest(Request $request, ?string $routeKey = null): ?string
    {
        if (! self::keyIsInQuery()) {
            return $routeKey;
        }

        $key = $request->query(self::keyParameter());

        return is_string($key) && $key !== '' ? $key : null;
    }

    public static function announcePath(): string
    {
        return self::path('announce_path', 'announce');
    }

    public static function scrapePath(): string
    {
        return self::path('scrape_path', 'scrape');
    }

    /**
     * A configured path, normalised.
     *
     * Leading and trailing slashes are stripped because Laravel adds its own,
     * and 'announce/' would otherwise register a route nothing matches.
     */
    private static function path(string $key, string $default): string
    {
        $path = trim((string) config("bloodhound.routes.{$key}", $default), '/');

        return $path !== '' ? $path : $default;
    }
}
