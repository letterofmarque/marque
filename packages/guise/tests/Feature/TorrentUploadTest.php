<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Marque\Guise\Livewire\Torrent\Upload;
use Marque\Guise\Tests\TestUser;
use Marque\Trove\Models\Torrent;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    $this->uploader = TestUser::factory()->uploader()->create();
    Storage::fake('local');
});

function createTestTorrentFile(string $name = 'test'): UploadedFile
{
    // Create a minimal valid bencoded torrent structure
    $info = [
        'name' => $name,
        'piece length' => 262144,
        'pieces' => str_repeat('a', 20), // SHA1 hash is 20 bytes
        'length' => 1000,
    ];

    $torrent = [
        'announce' => 'http://tracker.example.com/announce',
        'info' => $info,
    ];

    $content = bencode($torrent);

    return UploadedFile::fake()->createWithContent('test.torrent', $content);
}

function bencode(mixed $data): string
{
    if (is_int($data)) {
        return "i{$data}e";
    }

    if (is_string($data)) {
        return strlen($data).':'.$data;
    }

    if (is_array($data)) {
        if (array_keys($data) === range(0, count($data) - 1)) {
            $encoded = 'l';
            foreach ($data as $item) {
                $encoded .= bencode($item);
            }

            return $encoded.'e';
        }

        ksort($data);
        $encoded = 'd';
        foreach ($data as $key => $value) {
            $encoded .= bencode((string) $key);
            $encoded .= bencode($value);
        }

        return $encoded.'e';
    }

    throw new InvalidArgumentException('Cannot bencode type: '.gettype($data));
}

test('upload page requires authentication', function () {
    $this->get(route('torrents.upload'))
        ->assertRedirect(route('login'));
});

test('regular user cannot access upload page', function () {
    $this->actingAs($this->user)
        ->get(route('torrents.upload'))
        ->assertForbidden();
});

test('uploader can view upload page', function () {
    $this->actingAs($this->uploader)
        ->get(route('torrents.upload'))
        ->assertOk()
        ->assertSeeLivewire(Upload::class);
});

test('upload page displays form fields', function () {
    $this->actingAs($this->uploader)
        ->get(route('torrents.upload'))
        ->assertOk()
        ->assertSee('Torrent File')
        ->assertSee('Name')
        ->assertSee('Description');
});

test('can upload a torrent file', function () {
    $file = createTestTorrentFile('My Test Torrent');

    Livewire::actingAs($this->uploader)
        ->test(Upload::class)
        ->set('torrentFile', $file)
        ->set('name', 'My Test Torrent')
        ->set('description', 'A test description')
        ->call('upload')
        ->assertRedirect(route('torrents.index'));

    expect(Torrent::where('name', 'My Test Torrent')->exists())->toBeTrue();
});

test('upload requires a torrent file', function () {
    Livewire::actingAs($this->uploader)
        ->test(Upload::class)
        ->set('name', 'My Test Torrent')
        ->call('upload')
        ->assertHasErrors(['torrentFile' => 'required']);
});

test('upload requires a name', function () {
    $file = createTestTorrentFile();

    Livewire::actingAs($this->uploader)
        ->test(Upload::class)
        ->set('torrentFile', $file)
        ->set('name', '')
        ->call('upload')
        ->assertHasErrors(['name' => 'required']);
});

test('description is optional', function () {
    $file = createTestTorrentFile('Optional Desc Test');

    Livewire::actingAs($this->uploader)
        ->test(Upload::class)
        ->set('torrentFile', $file)
        ->set('name', 'No Description Torrent')
        ->call('upload')
        ->assertRedirect(route('torrents.index'));

    $torrent = Torrent::where('name', 'No Description Torrent')->first();
    expect($torrent)->not->toBeNull()
        ->and($torrent->description)->toBeNull();
});

test('uploaded torrent is associated with authenticated user', function () {
    $file = createTestTorrentFile('User Assoc Test');

    Livewire::actingAs($this->uploader)
        ->test(Upload::class)
        ->set('torrentFile', $file)
        ->set('name', 'User Association Test')
        ->call('upload');

    $torrent = Torrent::where('name', 'User Association Test')->first();
    expect($torrent->user_id)->toBe($this->uploader->id);
});
