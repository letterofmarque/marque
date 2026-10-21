<?php

declare(strict_types=1);

namespace Marque\Usarrs\Support;

/**
 * Seconds as the two largest of days, hours and minutes: "1d 2h", "2h 1m".
 *
 * Stops at days on purpose. Carbon's cascade carries days into months and
 * years by its own month length, so two years of seedtime read "2yrs 2mos",
 * and a tracker's longest seeders are the ones who would see it (#11070).
 */
final class Duration
{
    public static function short(int $seconds): string
    {
        $days = intdiv($seconds, 86_400);
        $hours = intdiv($seconds % 86_400, 3_600);
        $minutes = intdiv($seconds % 3_600, 60);

        return match (true) {
            $days > 0 => "{$days}d {$hours}h",
            $hours > 0 => "{$hours}h {$minutes}m",
            default => "{$minutes}m",
        };
    }
}
