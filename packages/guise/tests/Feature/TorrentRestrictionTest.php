<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Marque\Guise\Tests\TestUser;
use Marque\Trove\Enums\Role;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

describe('restricted torrent detail page', function () {
    test('a user below the minimum role is forbidden', function () {
        $torrent = Torrent::factory()->restrictedTo(Role::Uploader)->create();

        $this->actingAs($this->user)
            ->get(route('torrents.show', $torrent))
            ->assertForbidden();
    });

    test('a user at the minimum role can view it', function () {
        $torrent = Torrent::factory()->restrictedTo(Role::Uploader)->create();

        $this->actingAs(TestUser::factory()->uploader()->create())
            ->get(route('torrents.show', $torrent))
            ->assertOk();
    });

    test('an unrestricted torrent is unaffected', function () {
        $torrent = Torrent::factory()->create();

        $this->actingAs($this->user)
            ->get(route('torrents.show', $torrent))
            ->assertOk();
    });
});

// Downloading hands over the .torrent, which carries the user's announce key.
// It must never be a weaker gate than viewing.
describe('restricted torrent download', function () {
    beforeEach(function () {
        Storage::fake('local');
        Storage::disk('local')->put('torrents/test.torrent', 'd8:announcee');
    });

    test('a user below the minimum role cannot download', function () {
        $torrent = Torrent::factory()
            ->restrictedTo(Role::Uploader)
            ->create(['torrent_file' => 'torrents/test.torrent']);

        $this->actingAs($this->user)
            ->get(route('torrents.download', $torrent))
            ->assertForbidden();
    });

    test('a user at the minimum role can download', function () {
        $torrent = Torrent::factory()
            ->restrictedTo(Role::Uploader)
            ->create(['torrent_file' => 'torrents/test.torrent']);

        $this->actingAs(TestUser::factory()->uploader()->create())
            ->get(route('torrents.download', $torrent))
            ->assertOk();
    });

    test('an unrestricted torrent downloads normally', function () {
        $torrent = Torrent::factory()->create(['torrent_file' => 'torrents/test.torrent']);

        $this->actingAs($this->user)
            ->get(route('torrents.download', $torrent))
            ->assertOk();
    });
});

describe('restricted torrents in the listing', function () {
    test('are absent for a user below the minimum role', function () {
        Torrent::factory()->create(['name' => 'Open Release']);
        Torrent::factory()->restrictedTo(Role::Uploader)->create(['name' => 'Internal Release']);

        $this->actingAs($this->user)
            ->get(route('torrents.index'))
            ->assertOk()
            ->assertSee('Open Release')
            ->assertDontSee('Internal Release');
    });

    test('are present for a user at the minimum role', function () {
        Torrent::factory()->restrictedTo(Role::Uploader)->create(['name' => 'Internal Release']);

        $this->actingAs(TestUser::factory()->uploader()->create())
            ->get(route('torrents.index'))
            ->assertOk()
            ->assertSee('Internal Release');
    });
});
