<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

/**
 * Where an announce comes from, for counting a member's locations.
 *
 * An IPv4 address is a location. An IPv6 address is not: a single connection
 * is usually handed a whole /64, and clients pick addresses from it freely, so
 * counting addresses would make one machine look like many. The /64 is the
 * location.
 */
final class Location
{
    public static function of(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        return bin2hex(substr($packed, 0, 8)).'::/64';
    }
}
