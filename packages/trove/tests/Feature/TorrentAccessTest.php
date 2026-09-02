<?php

declare(strict_types=1);

use Marque\Trove\Enums\Role;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Services\TorrentService;
use Marque\Trove\Support\ViewerScope;
use Marque\Trove\Tests\TestUser;

beforeEach(function () {
    $this->service = new TorrentService;
});

describe('min_role listing scope', function () {
    test('unrestricted torrents are visible to everyone, including guests', function () {
        Torrent::factory()->count(2)->create();

        expect($this->service->list(viewer: ViewerScope::guest())->total())->toBe(2);
    });

    test('a guest never sees a restricted torrent', function () {
        Torrent::factory()->create();
        Torrent::factory()->restrictedTo(Role::Uploader)->create();

        expect($this->service->list(viewer: ViewerScope::guest())->total())->toBe(1);
    });

    test('a user below the minimum role does not see the torrent', function () {
        Torrent::factory()->restrictedTo(Role::Uploader)->create();

        $user = TestUser::factory()->create();

        expect($this->service->list(viewer: ViewerScope::user($user))->total())->toBe(0);
    });

    test('a user at the minimum role sees the torrent', function () {
        Torrent::factory()->restrictedTo(Role::Uploader)->create();

        $uploader = TestUser::factory()->uploader()->create();

        expect($this->service->list(viewer: ViewerScope::user($uploader))->total())->toBe(1);
    });

    // The whole point of a ranked role: restricting to uploader must not
    // exclude the people above uploader.
    test('a user above the minimum role sees the torrent', function () {
        Torrent::factory()->restrictedTo(Role::Uploader)->create();

        expect($this->service->list(viewer: ViewerScope::user(TestUser::factory()->moderator()->create()))->total())->toBe(1)
            ->and($this->service->list(viewer: ViewerScope::user(TestUser::factory()->admin()->create()))->total())->toBe(1);
    });

    test('restriction is applied per torrent, not all or nothing', function () {
        Torrent::factory()->create(['name' => 'open']);
        Torrent::factory()->restrictedTo(Role::Uploader)->create(['name' => 'uploader only']);
        Torrent::factory()->restrictedTo(Role::Admin)->create(['name' => 'admin only']);

        $names = fn ($viewer) => $this->service->list(viewer: $viewer)->pluck('name')->sort()->values()->all();

        expect($names(ViewerScope::guest()))->toBe(['open'])
            ->and($names(ViewerScope::user(TestUser::factory()->uploader()->create())))->toBe(['open', 'uploader only'])
            ->and($names(ViewerScope::user(TestUser::factory()->admin()->create())))->toBe(['admin only', 'open', 'uploader only']);
    });

    test('search cannot be used to reach a restricted torrent', function () {
        Torrent::factory()->restrictedTo(Role::Admin)->create(['name' => 'Secret Release']);

        expect($this->service->list(search: 'Secret', viewer: ViewerScope::guest())->total())->toBe(0);
    });

    test('find is filtered too, so a direct id is not a way around the listing', function () {
        $torrent = Torrent::factory()->restrictedTo(Role::Admin)->create();

        expect($this->service->find($torrent->id, ViewerScope::guest()))->toBeNull()
            ->and($this->service->find($torrent->id, ViewerScope::user(TestUser::factory()->admin()->create())))
            ->not->toBeNull();
    });
});

describe('viewer defaulting', function () {
    // The fail-open case this design exists to prevent: an omitted viewer must
    // resolve to the authenticated user, never to "unfiltered".
    test('an omitted viewer resolves to the authenticated user', function () {
        Torrent::factory()->create();
        Torrent::factory()->restrictedTo(Role::Uploader)->create();

        $this->actingAs(TestUser::factory()->uploader()->create());

        expect($this->service->list()->total())->toBe(2);
    });

    test('an omitted viewer with nobody authenticated is a guest', function () {
        Torrent::factory()->create();
        Torrent::factory()->restrictedTo(Role::Uploader)->create();

        expect($this->service->list()->total())->toBe(1);
    });
});

describe('dead torrent filtering', function () {
    test('is off by default, so a torrent with no seeders still lists', function () {
        Torrent::factory()->dead()->create();

        expect($this->service->list()->total())->toBe(1);
    });

    test('hides seederless torrents when enabled', function () {
        Torrent::factory()->seeded()->create();
        Torrent::factory()->dead()->create();

        config()->set('trove.hide_dead_torrents', true);

        expect($this->service->list()->total())->toBe(1);
    });

    test('includeDead overrides the config', function () {
        Torrent::factory()->seeded()->create();
        Torrent::factory()->dead()->create();

        config()->set('trove.hide_dead_torrents', true);

        expect($this->service->list(includeDead: true)->total())->toBe(2);
    });

    test('find returns a dead torrent even when the listing hides it', function () {
        $torrent = Torrent::factory()->dead()->create();

        config()->set('trove.hide_dead_torrents', true);

        expect($this->service->find($torrent->id))->not->toBeNull();
    });

    test('access and liveness filters compose', function () {
        Torrent::factory()->seeded()->create(['name' => 'alive open']);
        Torrent::factory()->dead()->create(['name' => 'dead open']);
        Torrent::factory()->seeded()->restrictedTo(Role::Admin)->create(['name' => 'alive restricted']);

        config()->set('trove.hide_dead_torrents', true);

        expect($this->service->list(viewer: ViewerScope::guest())->pluck('name')->all())
            ->toBe(['alive open']);
    });
});
