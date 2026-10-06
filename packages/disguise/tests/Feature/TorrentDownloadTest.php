<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Marque\Disguise\Livewire\Torrent\Upload;
use Marque\Disguise\Tests\TestUser;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\PrivateFlag;
use Marque\Trove\Models\Torrent;

// #10947 on the public frontend: a download gets the tracker's announce URL and
// a link back here, around the stored info bytes; uploads meet the tracker's
// private-flag rule before anything is stored.

function disguisePolicy(PrivateFlag $flag = PrivateFlag::Allow, ?string $url = 'https://tracker.example/announce'): void
{
    app()->instance(TorrentFilePolicyInterface::class, new class($flag, $url) implements TorrentFilePolicyInterface
    {
        public function __construct(private PrivateFlag $flag, private ?string $url) {}

        public function privateFlag(): PrivateFlag
        {
            return $this->flag;
        }

        public function announceUrlFor(?UserInterface $user): ?string
        {
            return $this->url;
        }
    });
}

beforeEach(function () {
    Storage::fake('local');
    $this->info = 'd6:lengthi5e4:name4:test12:piece lengthi16384e6:pieces20:'.str_repeat('a', 20).'e';
    Storage::disk('local')->put('torrents/t.torrent', 'd8:announce17:http://uploader/a4:info'.$this->info.'e');
    $this->torrent = Torrent::factory()->create(['torrent_file' => 'torrents/t.torrent', 'info_hash' => sha1($this->info)]);
});

test('a guest\'s download carries the tracker\'s announce URL and a link to the torrent page', function () {
    disguisePolicy();

    $raw = Bencode::rawDictionary($this->get(route('torrents.download', $this->torrent))->assertOk()->streamedContent());

    expect(Bencode::decode($raw['announce']))->toBe('https://tracker.example/announce')
        ->and(Bencode::decode($raw['comment']))->toBe(route('torrents.show', $this->torrent))
        ->and(sha1($raw['info']))->toBe($this->torrent->info_hash);
});

test('serves the stored file unchanged when no tracker is installed', function () {
    expect($this->get(route('torrents.download', $this->torrent))->assertOk()->streamedContent())
        ->toBe(Storage::disk('local')->get('torrents/t.torrent'));
});

test('refuses rather than hand out a file the tracker has no announce URL for', function () {
    disguisePolicy(url: null);

    $this->actingAs(TestUser::factory()->create())
        ->get(route('torrents.download', $this->torrent))
        ->assertForbidden();
});

test('an upload that breaks the private-flag rule is refused on the file field and not stored', function () {
    disguisePolicy(PrivateFlag::Disallow);
    $private = Bencode::encode(['announce' => 'http://x/a', 'info' => ['length' => 5, 'name' => 'p', 'piece length' => 16384, 'pieces' => str_repeat('a', 20), 'private' => 1]]);

    Livewire::actingAs(TestUser::factory()->admin()->create())
        ->test(Upload::class)
        ->set('torrentFile', UploadedFile::fake()->createWithContent('p.torrent', $private))
        ->set('name', 'Private One')
        ->call('upload')
        ->assertHasErrors(['torrentFile']);

    expect(Torrent::where('name', 'Private One')->exists())->toBeFalse();
});
