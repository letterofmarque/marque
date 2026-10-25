<?php

declare(strict_types=1);

// #11105: the unit was picked by counting decimal digits, so near each unit
// boundary the same figure could read differently here and on the dashboard,
// which uses Number::fileSize. It now says exactly what the dashboard says.

use Illuminate\Support\Number;
use Marque\Bloodhound\Tests\FillableTrackerUser;

function humanDownloaded(int $bytes): string
{
    return (new FillableTrackerUser)->forceFill(['downloaded' => $bytes])->getDownloadedForHumans();
}

it('reads 1024-based, to two decimals', function (int $bytes, string $expected) {
    expect(humanDownloaded($bytes))->toBe($expected);
})->with([
    'zero' => [0, '0.00 B'],
    'under 0.9 KB' => [900, '900.00 B'],
    'past 0.9 KB, as the dashboard switches' => [1000, '0.98 KB'],
    'one KB' => [1024, '1.00 KB'],
    'a decimal billion' => [1_000_000_000, '0.93 GB'],
    'one GB' => [1024 ** 3, '1.00 GB'],
    'twenty GB' => [20 * 1024 ** 3, '20.00 GB'],
    'one TB' => [1024 ** 4, '1.00 TB'],
]);

it('says exactly what Number::fileSize says, including where the digit count used to disagree', function () {
    // 950 * 1024 bytes has 6 digits, so the old helper said KB where the
    // dashboard said MB.
    foreach ([1000, 950 * 1024, 1_000_000, 1_000_000_000, 5 * 1024 ** 3 - 1] as $bytes) {
        expect(humanDownloaded($bytes))->toBe(Number::fileSize($bytes, 2));
    }
});
