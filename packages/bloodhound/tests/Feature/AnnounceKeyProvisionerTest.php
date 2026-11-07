<?php

declare(strict_types=1);

// Job #109 step 5: AnnounceKeyProvisioner, the race-safe half of lazy
// migration. The concurrent case is in tests/Concurrency.

use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Bloodhound\Services\AnnounceKeyProvisioner;
use Marque\Bloodhound\Tests\TestUser;

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
