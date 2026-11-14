<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The limits on sign-up and on the mail anyone can make usarrs send (Job #366).
 *
 * Each attempt is counted before the work is done, as an atomic increment,
 * the same as the login limit: checking first and counting after would let a
 * parallel burst through before any of it was counted. Nothing here depends
 * on whether an address belongs to an account, so a refusal reveals nothing
 * about which addresses exist.
 */
final class Throttle
{
    /**
     * Count one attempt against $key. Returns the message to show when this
     * attempt is over the limit, or null when it may go ahead. A limit of
     * zero or less is off.
     */
    public static function hit(string $key, int $max, int $decaySeconds): ?string
    {
        if ($max <= 0) {
            return null;
        }

        if (RateLimiter::hit($key, $decaySeconds) <= $max) {
            return null;
        }

        return self::message(RateLimiter::availableIn($key));
    }

    /**
     * An address as a key part: case-folded and transliterated, so MEMBER@
     * and member@ share one count (as the login limit does).
     */
    public static function address(string $email): string
    {
        return Str::transliterate(Str::lower($email));
    }

    /** A per-IP limit from `usarrs.limits`. */
    public static function perIp(string $limit, int $default): int
    {
        return (int) config("usarrs.limits.{$limit}", $default);
    }

    private static function message(int $seconds): string
    {
        // An hourly limit can leave most of an hour: say minutes, not 3540 seconds.
        return $seconds > 90
            ? __('Too many attempts. Try again in :minutes minutes.', ['minutes' => (int) ceil($seconds / 60)])
            : __('Too many attempts. Try again in :seconds seconds.', ['seconds' => max($seconds, 1)]);
    }
}
