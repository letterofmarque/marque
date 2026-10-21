<?php

declare(strict_types=1);

// #11070 item 6. A key is issued at creation to a user created verified, or by
// the Verified event. A user verified any other way (email_verified_at set
// directly, as an import or an admin does) or inserted without Eloquent never
// got one, and the legacy import twentyt is planning (#10608) does both.

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Tests\TrackerUser;
use Marque\Bloodhound\Tests\VerifyingTrackerUser;
use Marque\Trove\Contracts\TrackerStatsInterface;

function keyFor(object $user): ?string
{
    return app(TrackerStatsInterface::class)->announceKeyFor($user);
}

function importedUser(string $email, bool $verified): int
{
    return DB::table('users')->insertGetId([
        'name' => 'Imported',
        'email' => $email,
        'password' => 'x',
        'email_verified_at' => $verified ? now() : null,
    ]);
}

describe('a user verified without the Verified event', function () {
    it('is issued a key when email_verified_at is set directly', function () {
        $user = VerifyingTrackerUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'p']);

        $user->forceFill(['email_verified_at' => now()])->save();

        expect(keyFor($user))->not->toBeNull();
    });

    it('keeps the key it has when saved again', function () {
        $user = VerifyingTrackerUser::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'p', 'email_verified_at' => now()]);
        $key = keyFor($user);

        $user->forceFill(['email_verified_at' => now()->addMinute(), 'name' => 'B2'])->save();

        expect(keyFor($user))->toBe($key);
    });

    it('is not issued one by an unrelated save while unverified', function () {
        $user = VerifyingTrackerUser::create(['name' => 'C', 'email' => 'c@example.com', 'password' => 'p']);

        $user->forceFill(['name' => 'C2'])->save();

        expect(keyFor($user))->toBeNull();
    });
});

describe('bloodhound:issue-keys', function () {
    beforeEach(fn () => config(['trove.user_model' => VerifyingTrackerUser::class]));

    it('issues a key to every verified user without one, and to no one else', function () {
        $keyless = importedUser('imported@example.com', verified: true);
        $unverified = importedUser('unverified@example.com', verified: false);
        $keyed = VerifyingTrackerUser::create(['name' => 'K', 'email' => 'k@example.com', 'password' => 'p', 'email_verified_at' => now()]);
        $existing = keyFor($keyed);

        $this->artisan('bloodhound:issue-keys')
            ->expectsOutputToContain('Issued 1 announce key')
            ->assertSuccessful();

        expect(keyFor(VerifyingTrackerUser::find($keyless)))->not->toBeNull()
            ->and(keyFor(VerifyingTrackerUser::find($unverified)))->toBeNull()
            ->and(keyFor($keyed))->toBe($existing);
    });

    it('changes nothing with --dry-run, and says how many it would issue', function () {
        $keyless = importedUser('dry@example.com', verified: true);

        $this->artisan('bloodhound:issue-keys', ['--dry-run' => true])
            ->expectsOutputToContain('Would issue 1 announce key')
            ->assertSuccessful();

        expect(keyFor(VerifyingTrackerUser::find($keyless)))->toBeNull();
    });

    it('treats every user as verified when the user model has no verification', function () {
        config(['trove.user_model' => TrackerUser::class]);
        $keyless = importedUser('plain@example.com', verified: false);

        $this->artisan('bloodhound:issue-keys')->assertSuccessful();

        expect(keyFor(TrackerUser::find($keyless)))->not->toBeNull();
    });

    it('is a no-op the second time', function () {
        importedUser('twice@example.com', verified: true);

        $this->artisan('bloodhound:issue-keys')->assertSuccessful();
        $this->artisan('bloodhound:issue-keys')
            ->expectsOutputToContain('Issued 0 announce keys')
            ->assertSuccessful();
    });
});
