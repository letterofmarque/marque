<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use Illuminate\Http\Request;

/**
 * One announce URL shape: where its announce and scrape live, and where and
 * how it carries the announce key (Job #109, Spec #117).
 *
 * A tracker migrating onto Marque serves several at once: the native
 * /announce/{key} that new .torrent files carry, beside the legacy URL the old
 * files on strangers' disks still announce to. `bloodhound.routes` is the
 * primary endpoint, `bloodhound.endpoints` lists the others, and each is one of
 * these. AnnounceRouting builds the list.
 *
 * Every per-endpoint question is answered here, from one endpoint's own config,
 * so the router and the controllers cannot disagree about which pattern or
 * parameter applies.
 */
final class AnnounceEndpoint
{
    public const DEFAULT_PATTERN = '[0-9a-zA-Z]{32}';

    /**
     * @param  'path'|'query'  $keySource
     * @param  'failure'|'not_found'  $malformedKey
     */
    private function __construct(
        public readonly string $name,
        public readonly string $announcePath,
        public readonly string $scrapePath,
        public readonly string $keySource,
        public readonly string $keyParameter,
        public readonly string $keyPattern,
        public readonly string $malformedKey,
    ) {}

    /**
     * An endpoint from one block of config, with bloodhound.routes' defaults
     * and normalisation for anything missing or empty.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        $parameter = (string) ($config['key_parameter'] ?? '');
        $pattern = (string) ($config['key_pattern'] ?? '');

        return new self(
            name: $name,
            announcePath: self::path($config['announce_path'] ?? null, 'announce'),
            scrapePath: self::path($config['scrape_path'] ?? null, 'scrape'),
            // Anything other than 'query' is 'path': a typo in this key should
            // not silently produce a keyless tracker.
            keySource: ($config['key_source'] ?? null) === 'query' ? 'query' : 'path',
            keyParameter: $parameter !== '' ? $parameter : 'announce_key',
            keyPattern: $pattern !== '' ? $pattern : self::DEFAULT_PATTERN,
            // Only an explicit 'not_found' gets the router's 404. A published
            // config from before 8.1 has no such key, and gets the default.
            malformedKey: ($config['malformed_key'] ?? null) === 'not_found' ? 'not_found' : 'failure',
        );
    }

    public function keyIsInQuery(): bool
    {
        return $this->keySource === 'query';
    }

    /**
     * Does this string match the endpoint's key format?
     *
     * Anchored here rather than in config, so a consumer cannot accidentally
     * supply an unanchored pattern and have 'junk<validkey>junk' accepted.
     * The `D` modifier stops a trailing newline satisfying `$`.
     */
    public function matches(string $key): bool
    {
        return preg_match('/^(?:'.$this->keyPattern.')$/D', $key) === 1;
    }

    /**
     * Pull the announce key out of a request, wherever this endpoint carries it.
     *
     * Returns null when the key is absent entirely, which the caller must treat
     * as a failure rather than as an empty key: in query mode the parameter can
     * simply be missing, where in path mode the router would already have
     * refused the request.
     */
    public function keyFromRequest(Request $request, ?string $routeKey = null): ?string
    {
        if (! $this->keyIsInQuery()) {
            return $routeKey;
        }

        $key = $request->query($this->keyParameter);

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * A configured path, normalised.
     *
     * Leading and trailing slashes are stripped because Laravel adds its own,
     * and 'announce/' would otherwise register a route nothing matches.
     */
    private static function path(mixed $value, string $default): string
    {
        $path = trim((string) $value, '/');

        return $path !== '' ? $path : $default;
    }
}
