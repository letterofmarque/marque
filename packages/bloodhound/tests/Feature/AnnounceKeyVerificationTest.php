<?php

declare(strict_types=1);

// usarrs #10879. A key is issued when a user is created, so every sign-up held
// one before anyone had proven the address: a squatter could announce, and the
// owner proving the inbox later couldn't take that key back without making
// everyone re-download their .torrent files. When the app verifies addresses,
// the key now waits for verification.

use Illuminate\Auth\Events\Verified;
use Marque\Bloodhound\Tests\TrackerUser;
use Marque\Bloodhound\Tests\VerifyingTrackerUser;
use Marque\Trove\Contracts\TrackerStatsInterface;

function issuedKey(object $user): ?string
{
    return app(TrackerStatsInterface::class)->announceKeyFor($user);
}

function newTrackerUser(string $class, array $attributes = []): object
{
    return $class::create(['name' => 'New User', 'email' => 'new@example.com', 'password' => 'password', ...$attributes]);
}

it('issues no key to a user whose address is unverified', function () {
    $user = newTrackerUser(VerifyingTrackerUser::class);

    expect(issuedKey($user))->toBeNull();
});

it('issues a key at creation to a user created already verified', function () {
    $user = newTrackerUser(VerifyingTrackerUser::class, ['email_verified_at' => now()]);

    expect(issuedKey($user))->not->toBeNull();
});

it('issues the key once the address is verified', function () {
    $user = newTrackerUser(VerifyingTrackerUser::class);

    $user->markEmailAsVerified();
    event(new Verified($user));

    expect(issuedKey($user))->not->toBeNull();
});

it('never rotates a key that already exists when an address is verified', function () {
    // Rotating would make the user re-download every .torrent they run.
    $user = newTrackerUser(VerifyingTrackerUser::class, ['email_verified_at' => now()]);
    $key = issuedKey($user);

    event(new Verified($user));

    expect(issuedKey($user))->toBe($key);
});

it('still issues a key at creation when the app does not verify addresses at all', function () {
    $user = newTrackerUser(TrackerUser::class);

    expect(issuedKey($user))->not->toBeNull();
});
