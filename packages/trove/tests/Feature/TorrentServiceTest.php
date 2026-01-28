<?php

declare(strict_types=1);

use Marque\Trove\Models\Torrent;
use Marque\Trove\Services\TorrentService;
use Marque\Trove\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    $this->service = new TorrentService;
});

describe('TorrentService', function () {
    test('list returns paginated torrents', function () {
        Torrent::create([
            'info_hash' => str_repeat('e', 40),
            'name' => 'Torrent 1',
            'user_id' => $this->user->id,
        ]);

        Torrent::create([
            'info_hash' => str_repeat('f', 40),
            'name' => 'Torrent 2',
            'user_id' => $this->user->id,
        ]);

        $result = $this->service->list();

        expect($result)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class)
            ->and($result->total())->toBe(2);
    });

    test('list can search by name', function () {
        Torrent::create([
            'info_hash' => str_repeat('a', 40),
            'name' => 'Finding Nemo',
            'user_id' => $this->user->id,
        ]);

        Torrent::create([
            'info_hash' => str_repeat('b', 40),
            'name' => 'Other Movie',
            'user_id' => $this->user->id,
        ]);

        $result = $this->service->list(search: 'Nemo');

        expect($result->total())->toBe(1)
            ->and($result->first()->name)->toBe('Finding Nemo');
    });

    test('find returns a torrent by id', function () {
        $torrent = Torrent::create([
            'info_hash' => str_repeat('g', 40),
            'name' => 'Find Me',
            'user_id' => $this->user->id,
        ]);

        $found = $this->service->find($torrent->id);

        expect($found)->not->toBeNull()
            ->and($found->name)->toBe('Find Me');
    });

    test('find returns null for non-existent id', function () {
        $found = $this->service->find(99999);

        expect($found)->toBeNull();
    });

    test('findByInfoHash returns a torrent by hash', function () {
        $hash = str_repeat('h', 40);

        Torrent::create([
            'info_hash' => $hash,
            'name' => 'Hash Search',
            'user_id' => $this->user->id,
        ]);

        $found = $this->service->findByInfoHash($hash);

        expect($found)->not->toBeNull()
            ->and($found->name)->toBe('Hash Search');
    });

    test('findByInfoHash is case insensitive', function () {
        $hash = str_repeat('i', 40);

        Torrent::create([
            'info_hash' => $hash,
            'name' => 'Case Test',
            'user_id' => $this->user->id,
        ]);

        $found = $this->service->findByInfoHash(strtoupper($hash));

        expect($found)->not->toBeNull()
            ->and($found->name)->toBe('Case Test');
    });

    test('create creates a torrent for a user', function () {
        $torrent = $this->service->create([
            'info_hash' => str_repeat('j', 40),
            'name' => 'Service Created',
            'description' => 'Created via service',
            'size' => 5000000,
        ], $this->user);

        expect($torrent)->toBeInstanceOf(Torrent::class)
            ->and($torrent->name)->toBe('Service Created')
            ->and($torrent->user_id)->toBe($this->user->id);
    });

    test('update modifies a torrent', function () {
        $torrent = Torrent::create([
            'info_hash' => str_repeat('k', 40),
            'name' => 'Original Name',
            'user_id' => $this->user->id,
        ]);

        $updated = $this->service->update($torrent, [
            'name' => 'Updated Name',
            'description' => 'New description',
        ]);

        expect($updated->name)->toBe('Updated Name')
            ->and($updated->description)->toBe('New description');
    });

    test('delete removes a torrent', function () {
        $torrent = Torrent::create([
            'info_hash' => str_repeat('l', 40),
            'name' => 'Delete Me',
            'user_id' => $this->user->id,
        ]);

        $id = $torrent->id;
        $result = $this->service->delete($torrent);

        expect($result)->toBeTrue()
            ->and(Torrent::find($id))->toBeNull();
    });
});
