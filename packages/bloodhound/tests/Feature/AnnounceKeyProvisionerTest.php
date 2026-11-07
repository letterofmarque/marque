<?php

declare(strict_types=1);

// Job #109 step 5: AnnounceKeyProvisioner, the race-safe half of lazy
// migration. The concurrent case is in tests/Concurrency.

use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Services\AnnounceKeyProvisioner;
use Marque\Bloodhound\Tests\TestUser;
use Marque\Bloodhound\Tests\TrackerUser;

it('finds the member a key belongs to', function () {
    $user = makeTrackerUser('aaaabbbbccccddddeeeeffffgggghhhh', 'found@example.com');

    expect(app(AnnounceKeyProvisioner::class)->userForKey('aaaabbbbccccddddeeeeffffgggghhhh')?->id)->toBe($user->id)
        ->and(app(AnnounceKeyProvisioner::class)->userForKey('zzzzbbbbccccddddeeeeffffgggghhhh'))->toBeNull();
});

it('creates a member for an unknown key, with that key', function () {
    $user = app(AnnounceKeyProvisioner::class)->provision('feedfacefeedfacefeedfacefeedface', fn () => TestUser::create(['name' => 'N', 'email' => 'n@example.com', 'password' => 'p']));

    expect(AnnounceKey::query()->where('key', 'feedfacefeedfacefeedfacefeedface')->value('user_id'))->toBe($user->id);
});

it('does not call the creator for a key Marque already knows', function () {
    $user = makeTrackerUser('feedfacefeedfacefeedfacefeedface', 'known@example.com');
    $called = false;

    $found = app(AnnounceKeyProvisioner::class)->provision('feedfacefeedfacefeedfacefeedface', function () use (&$called) {
        $called = true;

        return null;
    });

    expect($found?->id)->toBe($user->id)->and($called)->toBeFalse();
});

it('creates nothing when the creator returns no one', function () {
    $keys = AnnounceKey::query()->count();

    expect(app(AnnounceKeyProvisioner::class)->provision('feedfacefeedfacefeedfacefeedface', fn () => null))->toBeNull()
        ->and(AnnounceKey::query()->count())->toBe($keys);
});

it('rolls back the member it created when the creator throws after creating them', function () {
    expect(fn () => app(AnnounceKeyProvisioner::class)->provision('feedfacefeedfacefeedfacefeedface', function () {
        TestUser::create(['name' => 'H', 'email' => 'half@example.com', 'password' => 'p']);

        throw new RuntimeException('the old tracker went away');
    }))->toThrow(RuntimeException::class);

    expect(TestUser::query()->where('email', 'half@example.com')->exists())->toBeFalse();
});

// Found by the Job #109 check: work that commits on its own (a ledger
// adjustment, when the ledger has its own database) can't go in the creator,
// whose transaction a lost race rolls back. $created runs after the member is
// committed, and only in the request that created them.
describe('after the member is created', function () {
    it('runs once, after commit, with the new member', function () {
        $seen = [];

        $user = app(AnnounceKeyProvisioner::class)->provision(
            'feedfacefeedfacefeedfacefeedface',
            fn () => TestUser::create(['name' => 'N', 'email' => 'after@example.com', 'password' => 'p']),
            function ($created) use (&$seen) {
                $seen[] = [$created->getAuthIdentifier(), DB::transactionLevel(), AnnounceKey::query()->where('key', 'feedfacefeedfacefeedfacefeedface')->exists()];
            },
        );

        // Inside the test's own wrapping transaction (level 1), not the
        // provisioning one (which would be 2), with the key already written.
        expect($seen)->toBe([[$user->getAuthIdentifier(), 1, true]]);
    });

    it('does not run for a key Marque already knows, or when no one is created', function () {
        makeTrackerUser('feedfacefeedfacefeedfacefeedface', 'known@example.com');
        $runs = 0;

        app(AnnounceKeyProvisioner::class)->provision('feedfacefeedfacefeedfacefeedface', fn () => null, function () use (&$runs) {
            $runs++;
        });
        app(AnnounceKeyProvisioner::class)->provision('deadbeefdeadbeefdeadbeefdeadbeef', fn () => null, function () use (&$runs) {
            $runs++;
        });

        expect($runs)->toBe(0);
    });
});

// Found by the 11.3.0 release read-through: a model using HasTrackerStats is
// issued a fresh key the moment it's created, verified (or not verifying
// addresses at all). Provisioning then added the old key as a SECOND row for
// the member, which announce_keys' one-key-per-member index refused: an HTTP
// error on every first announce. The member's key becomes the old one instead.
it('gives a member whose model issues keys on create their old key, and only that', function () {
    $user = app(AnnounceKeyProvisioner::class)->provision(
        'feedfacefeedfacefeedfacefeedface',
        fn () => TrackerUser::create(['name' => 'T', 'email' => 'tracker-user@example.com', 'password' => 'p']),
    );

    expect(AnnounceKey::query()->where('user_id', $user->getAuthIdentifier())->pluck('key')->all())->toBe(['feedfacefeedfacefeedfacefeedface']);
});
