<?php

declare(strict_types=1);

use Marque\Disguise\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

test('edit page requires authentication', function () {
    $torrent = Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Test Torrent',
        'user_id' => $this->user->id,
    ]);

    $this->get(route('torrents.edit', $torrent))
        ->assertRedirect(route('login'));
});
