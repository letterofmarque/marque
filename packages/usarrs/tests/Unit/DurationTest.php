<?php

declare(strict_types=1);

use Marque\Usarrs\Support\Duration;

// Seed and leech time on the dashboard (#11070). Carbon's cascade turns days
// into months and years by its own lengths, so 731 days read "2yrs 2mos". A
// tracker's long-time seeder is exactly who sees that, so this stops at days.
it('shows the two largest units, stopping at days', function (int $seconds, string $expected) {
    expect(Duration::short($seconds))->toBe($expected);
})->with([
    'nothing' => [0, '0m'],
    'under a minute' => [59, '0m'],
    'minutes' => [125, '2m'],
    'hours and minutes' => [7_260, '2h 1m'],
    'days and hours' => [93_784, '1d 2h'],
    'two years' => [731 * 86_400, '731d 0h'],
]);
