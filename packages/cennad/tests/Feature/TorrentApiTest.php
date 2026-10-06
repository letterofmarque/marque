<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Marque\Cennad\Tests\TestUser;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\PrivateFlag;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

describe('GET /api/torrents', function () {
    test('requires authentication by default', function () {
        $this->getJson('/api/torrents')
            ->assertUnauthorized();
    });

    test('is accessible to an authenticated user', function () {
        $this->actingAs($this->user);

        $this->getJson('/api/torrents')
            ->assertOk();
    });

    // Routes bind their middleware at registration, so these two rebuild the
    // application with the config in place rather than setting it at runtime.

    test('can be opened to guests by dropping auth from read_middleware', function () {
        $this->rebootWithConfig(['cennad.read_middleware' => ['api']]);

        $this->getJson('/api/torrents')
            ->assertOk();
    });

    // A published config from 3.x still carries public_middleware, and
    // mergeConfigFrom() will have injected read_middleware alongside it. The old
    // key has to win, or an explicit setting is silently discarded.
    test('honours the deprecated public_middleware key over the merged default', function () {
        $this->rebootWithConfig([
            'cennad.read_middleware' => ['api', 'auth'],
            'cennad.public_middleware' => ['api'],
        ]);

        $this->getJson('/api/torrents')
            ->assertOk();
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
    test('requires authentication by default', function () {
        $torrent = Torrent::factory()->create();

        $this->getJson("/api/torrents/{$torrent->id}")
            ->assertUnauthorized();
    });

    test('is accessible to an authenticated user', function () {
        $torrent = Torrent::factory()->create();

        $this->actingAs($this->user);

        $this->getJson("/api/torrents/{$torrent->id}")
            ->assertOk();
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

describe('POST /api/torrents', function () {
    test('requires authentication', function () {
        $this->postJson('/api/torrents')
            ->assertUnauthorized();
    });

    test('regular user cannot upload', function () {
        $this->actingAs($this->user);

        $this->postJson('/api/torrents', [
            'name' => 'Test',
        ])->assertForbidden();
    });

    test('requires torrent file and name', function () {
        $uploader = TestUser::factory()->uploader()->create();

        $this->actingAs($uploader);

        $this->postJson('/api/torrents', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['torrent_file', 'name']);
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

// #10947: API uploads meet the same tracker rules as the web forms.
describe('POST /api/torrents and the tracker\'s rules', function () {
    function apiTorrent(bool $private): UploadedFile
    {
        $info = ['length' => 5, 'name' => 'api', 'piece length' => 16384, 'pieces' => str_repeat('a', 20)] + ($private ? ['private' => 1] : []);

        return UploadedFile::fake()->createWithContent('api.torrent', Bencode::encode(['announce' => 'http://x/a', 'info' => $info]));
    }

    function apiPolicy(PrivateFlag $flag): void
    {
        app()->instance(TorrentFilePolicyInterface::class, new class($flag) implements TorrentFilePolicyInterface
        {
            public function __construct(private PrivateFlag $flag) {}

            public function privateFlag(): PrivateFlag
            {
                return $this->flag;
            }

            public function announceUrlFor(?UserInterface $user): ?string
            {
                return 'https://tracker.example/announce';
            }
        });
    }

    beforeEach(function () {
        Storage::fake(config('trove.storage_disk', 'local'));
        $this->actingAs(TestUser::factory()->uploader()->create());
    });

    test('a refused torrent is a 422 on torrent_file, saying what to change, and nothing is stored', function () {
        apiPolicy(PrivateFlag::Require);

        $this->postJson('/api/torrents', ['torrent_file' => apiTorrent(false), 'name' => 'Public'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['torrent_file' => 'private']);

        expect(Torrent::count())->toBe(0);
    });

    test('an accepted torrent is created, with any warnings in meta', function () {
        apiPolicy(PrivateFlag::WarnIfPublic);

        $this->postJson('/api/torrents', ['torrent_file' => apiTorrent(false), 'name' => 'Warned'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Warned')
            ->assertJsonPath('meta.warnings.0', fn ($w) => str_contains($w, 'isn\'t marked private'));
    });

    test('a file that is not a torrent is a 422, not a server error', function () {
        $this->postJson('/api/torrents', [
            'torrent_file' => UploadedFile::fake()->createWithContent('junk.torrent', 'not bencode'),
            'name' => 'Junk',
        ])->assertUnprocessable()->assertJsonValidationErrors(['torrent_file']);
    });

    // Job #158 criterion 375: every mode, at this entry point, for both kinds of torrent.
    test('refuses or accepts per mode, never altering an accepted file', function (PrivateFlag $flag, bool $private, ?string $refusalSays, ?string $warningSays) {
        apiPolicy($flag);
        $file = apiTorrent($private);
        $bytes = file_get_contents($file->getRealPath());

        $response = $this->postJson('/api/torrents', ['torrent_file' => $file, 'name' => 'Mode Test']);

        if ($refusalSays !== null) {
            $response->assertUnprocessable()->assertJsonValidationErrors(['torrent_file' => $refusalSays]);
            expect(Torrent::count())->toBe(0);

            return;
        }

        $response->assertCreated();
        $torrent = Torrent::sole();
        expect(Storage::disk(config('trove.storage_disk', 'local'))->get($torrent->torrent_file))->toBe($bytes)
            ->and($torrent->info_hash)->toBe(sha1(Bencode::rawDictionary($bytes)['info']));

        $warningSays === null
            ? $response->assertJsonPath('meta.warnings', [])
            : $response->assertJsonPath('meta.warnings.0', fn ($w) => str_contains($w, $warningSays));
    })->with([
        'allow, public' => [PrivateFlag::Allow, false, null, null],
        'allow, private' => [PrivateFlag::Allow, true, null, null],
        'require, public' => [PrivateFlag::Require, false, 'ticked', null],
        'require, private' => [PrivateFlag::Require, true, null, null],
        'disallow, private' => [PrivateFlag::Disallow, true, 'unticked', null],
        'disallow, public' => [PrivateFlag::Disallow, false, null, null],
        'warn_if_public, public' => [PrivateFlag::WarnIfPublic, false, null, 'isn\'t marked private'],
        'warn_if_public, private' => [PrivateFlag::WarnIfPublic, true, null, null],
        'warn_if_private, private' => [PrivateFlag::WarnIfPrivate, true, null, 'is marked private'],
        'warn_if_private, public' => [PrivateFlag::WarnIfPrivate, false, null, null],
    ]);
});
