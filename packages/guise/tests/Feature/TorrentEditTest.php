<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Guise\Livewire\Torrent\Edit;
use Marque\Guise\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    $this->moderator = TestUser::factory()->moderator()->create();
});

test('edit page requires authentication', function () {
    $torrent = Torrent::factory()->create();

    $this->get(route('torrents.edit', $torrent))
        ->assertRedirect(route('login'));
});

test('non-owner cannot edit torrent', function () {
    $owner = TestUser::factory()->create();
    $torrent = Torrent::factory()->for($owner, 'user')->create();

    $this->actingAs($this->user)
        ->get(route('torrents.edit', $torrent))
        ->assertForbidden();
});

test('owner can view edit page', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'My Torrent',
        'description' => 'Original description',
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('torrents.edit', $torrent))
        ->assertOk()
        ->assertSeeLivewire(Edit::class);
});

test('moderator can edit any torrent', function () {
    $torrent = Torrent::factory()->for($this->user, 'user')->create();

    $this->actingAs($this->moderator)
        ->get(route('torrents.edit', $torrent))
        ->assertOk()
        ->assertSeeLivewire(Edit::class);
});

test('edit form is populated with current values', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('b', 40),
        'name' => 'Original Name',
        'description' => 'Original Description',
        'user_id' => $this->user->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(Edit::class, ['torrent' => $torrent])
        ->assertSet('name', 'Original Name')
        ->assertSet('description', 'Original Description');
});

test('owner can update torrent', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('c', 40),
        'name' => 'Original Name',
        'user_id' => $this->user->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(Edit::class, ['torrent' => $torrent])
        ->set('name', 'Updated Name')
        ->set('description', 'New description')
        ->call('save')
        ->assertRedirect(route('torrents.show', $torrent));

    expect($torrent->fresh())
        ->name->toBe('Updated Name')
        ->description->toBe('New description');
});

test('name is required', function () {
    $torrent = Torrent::factory()->for($this->user, 'user')->create();

    Livewire::actingAs($this->user)
        ->test(Edit::class, ['torrent' => $torrent])
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

test('description can be cleared', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('d', 40),
        'name' => 'Test Torrent',
        'description' => 'Original description',
        'user_id' => $this->user->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(Edit::class, ['torrent' => $torrent])
        ->set('description', '')
        ->call('save')
        ->assertRedirect(route('torrents.show', $torrent));

    expect($torrent->fresh()->description)->toBeNull();
});
