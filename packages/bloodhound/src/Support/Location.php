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
 *
 * A dual-stack peer is at one place with two locations, its IPv4 address and
 * its IPv6 /64 (#11112). Two peers are at the same place when they share
 * either, so one machine on both families is never counted twice.
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

    /**
     * The locations of a peer's addresses, one per family it has.
     *
     * @param  array{ipv4: ?string, ipv6: ?string}  $addresses  as PeerAddresses gives them
     * @return list<string>
     */
    public static function ofAddresses(array $addresses): array
    {
        return array_values(array_map(self::of(...), array_filter([$addresses['ipv4'], $addresses['ipv6']])));
    }

    /**
     * How many places these peers are at: peers that share a location are at
     * one place.
     *
     * @param  list<list<string>>  $peers  each peer's locations
     */
    public static function places(array $peers): int
    {
        $places = [];

        foreach ($peers as $locations) {
            $merged = $locations;

            foreach ($places as $i => $place) {
                if (array_intersect($place, $merged) !== []) {
                    $merged = array_values(array_unique([...$merged, ...$place]));
                    unset($places[$i]);
                }
            }

            $places[] = $merged;
        }

        return count($places);
    }
}
