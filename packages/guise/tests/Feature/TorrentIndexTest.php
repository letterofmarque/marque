<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Guise\Livewire\Torrent\Index;
use Marque\Guise\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('torrent index page requires authentication', function () {
    $this->get(route('torrents.index'))
        ->assertRedirect(route('login'));
});

test('authenticated user can view torrent index', function () {
    $this->actingAs($this->user)
        ->get(route('torrents.index'))
        ->assertOk()
        ->assertSeeLivewire(Index::class);
});

test('torrent index displays torrents', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Test Torrent Display',
        'size' => 1073741824,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('torrents.index'))
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

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->set('search', 'Nemo')
        ->assertSee('Finding Nemo Torrent')
        ->assertDontSee('Other Movie Torrent');
});

test('torrent index shows empty state when no torrents', function () {
    $this->actingAs($this->user)
        ->get(route('torrents.index'))
        ->assertOk()
        ->assertSee('No torrents found');
});
