<?php

declare(strict_types=1);

use Marque\Disguise\Livewire\Torrent\Show;
use Marque\Disguise\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('torrent show page is publicly accessible', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Test Torrent',
        'user_id' => $this->user->id,
    ]);

    $this->get(route('torrents.show', $torrent))
        ->assertOk()
        ->assertSeeLivewire(Show::class);
});

test('guest can view torrent details', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Test Torrent',
        'description' => 'A detailed description',
        'size' => 1073741824,
        'user_id' => $this->user->id,
    ]);

    $this->get(route('torrents.show', $torrent))
        ->assertOk()
        ->assertSee('Test Torrent')
        ->assertSee('A detailed description')
        ->assertSee('1 GB');
});

test('torrent show page displays uploader name', function () {
    $uploader = TestUser::factory()->create(['name' => 'John Uploader']);
    $torrent = Torrent::create([
        'info_hash' => str_repeat('b', 40),
        'name' => 'Test Torrent',
        'user_id' => $uploader->id,
    ]);

    $this->get(route('torrents.show', $torrent))
        ->assertOk()
        ->assertSee('John Uploader');
});

test('returns 404 for non-existent torrent', function () {
    $this->get(route('torrents.show', 99999))
        ->assertNotFound();
});

test('edit button hidden from guests', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Test Torrent',
        'user_id' => $this->user->id,
    ]);

    $this->get(route('torrents.show', $torrent))
        ->assertOk()
        ->assertDontSee('Edit');
});
