<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Disguise\Livewire\Torrent\Index;
use Marque\Disguise\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('torrent index page is publicly accessible', function () {
    $this->get(route('torrents.index'))
        ->assertOk()
        ->assertSeeLivewire(Index::class);
});

test('torrent index displays torrents to guests', function () {
    Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Test Torrent Display',
        'size' => 1073741824,
        'user_id' => $this->user->id,
    ]);

    $this->get(route('torrents.index'))
        ->assertOk()
        ->assertSee('Test Torrent Display')
        ->assertSee('1 GB');
});

test('torrent index can search torrents', function () {
    Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Finding Nemo Torrent',
        'user_id' => $this->user->id,
    ]);

    Torrent::create([
        'info_hash' => str_repeat('b', 40),
        'name' => 'Other Movie Torrent',
        'user_id' => $this->user->id,
    ]);

    Livewire::test(Index::class)
        ->set('search', 'Nemo')
        ->assertSee('Finding Nemo Torrent')
        ->assertDontSee('Other Movie Torrent');
});

test('torrent index shows empty state when no torrents', function () {
    $this->get(route('torrents.index'))
        ->assertOk()
        ->assertSee('No torrents found');
});

test('upload button is shown to authenticated users', function () {
    $this->actingAs($this->user)
        ->get(route('torrents.index'))
        ->assertOk()
        ->assertSee('Upload');
});

test('upload button is hidden from guests', function () {
    $this->get(route('torrents.index'))
        ->assertOk()
        ->assertDontSee(route('torrents.upload'));
});
