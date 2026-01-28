<?php

declare(strict_types=1);

use Marque\Cennad\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

describe('GET /api/torrents', function () {
    test('requires authentication', function () {
        $this->getJson('/api/torrents')
            ->assertUnauthorized();
    });

    test('returns paginated torrents', function () {
        Torrent::factory()->count(3)->create();

        $this->actingAs($this->user);

        $this->getJson('/api/torrents')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'info_hash',
                        'name',
                        'description',
                        'size',
                        'size_formatted',
                        'file_count',
                        'has_torrent_file',
                        'created_at',
                        'updated_at',
                        'user',
                        'links',
                    ],
                ],
                'links',
                'meta',
            ]);
    });

    test('can search torrents', function () {
        Torrent::factory()->create(['name' => 'Finding Nemo']);
        Torrent::factory()->create(['name' => 'Other Movie']);

        $this->actingAs($this->user);

        $this->getJson('/api/torrents?search=Nemo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Finding Nemo');
    });

    test('can set per_page', function () {
        Torrent::factory()->count(10)->create();

        $this->actingAs($this->user);

        $this->getJson('/api/torrents?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5);
    });
});

describe('GET /api/torrents/{torrent}', function () {
    test('requires authentication', function () {
        $torrent = Torrent::factory()->create();

        $this->getJson("/api/torrents/{$torrent->id}")
            ->assertUnauthorized();
    });

    test('returns torrent details', function () {
        $torrent = Torrent::factory()->create([
            'name' => 'Test Torrent',
            'description' => 'Test description',
        ]);

        $this->actingAs($this->user);

        $this->getJson("/api/torrents/{$torrent->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Test Torrent')
            ->assertJsonPath('data.description', 'Test description')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'info_hash',
                    'name',
                    'description',
                    'size',
                    'size_formatted',
                    'file_count',
                    'has_torrent_file',
                    'created_at',
                    'updated_at',
                    'user' => ['id', 'name'],
                    'links' => ['self', 'download'],
                ],
            ]);
    });

    test('returns 404 for non-existent torrent', function () {
        $this->actingAs($this->user);

        $this->getJson('/api/torrents/99999')
            ->assertNotFound();
    });
});

describe('PUT /api/torrents/{torrent}', function () {
    test('requires authentication', function () {
        $torrent = Torrent::factory()->create();

        $this->putJson("/api/torrents/{$torrent->id}", ['name' => 'Updated'])
            ->assertUnauthorized();
    });

    test('owner can update torrent', function () {
        $torrent = Torrent::factory()->for($this->user, 'user')->create();

        $this->actingAs($this->user);

        $this->putJson("/api/torrents/{$torrent->id}", [
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.description', 'Updated description');

        expect($torrent->fresh()->name)->toBe('Updated Name');
    });

    test('non-owner cannot update torrent', function () {
        $torrent = Torrent::factory()->create();

        $this->actingAs($this->user);

        $this->putJson("/api/torrents/{$torrent->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    });

    test('moderator can update any torrent', function () {
        $moderator = TestUser::factory()->moderator()->create();
        $torrent = Torrent::factory()->create(['name' => 'Original']);

        $this->actingAs($moderator);

        $this->putJson("/api/torrents/{$torrent->id}", ['name' => 'Moderated'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Moderated');
    });

    test('validates name when provided', function () {
        $torrent = Torrent::factory()->for($this->user, 'user')->create();

        $this->actingAs($this->user);

        $this->putJson("/api/torrents/{$torrent->id}", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });
});

describe('DELETE /api/torrents/{torrent}', function () {
    test('requires authentication', function () {
        $torrent = Torrent::factory()->create();

        $this->deleteJson("/api/torrents/{$torrent->id}")
            ->assertUnauthorized();
    });

    test('regular user cannot delete torrent', function () {
        $torrent = Torrent::factory()->for($this->user, 'user')->create();

        $this->actingAs($this->user);

        $this->deleteJson("/api/torrents/{$torrent->id}")
            ->assertForbidden();
    });

    test('moderator can delete torrent', function () {
        $moderator = TestUser::factory()->moderator()->create();
        $torrent = Torrent::factory()->create();

        $this->actingAs($moderator);

        $this->deleteJson("/api/torrents/{$torrent->id}")
            ->assertNoContent();

        expect(Torrent::find($torrent->id))->toBeNull();
    });

    test('admin can delete torrent', function () {
        $admin = TestUser::factory()->admin()->create();
        $torrent = Torrent::factory()->create();

        $this->actingAs($admin);

        $this->deleteJson("/api/torrents/{$torrent->id}")
            ->assertNoContent();

        expect(Torrent::find($torrent->id))->toBeNull();
    });
});
