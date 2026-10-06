<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Marque\Guise\Tests\TestUser;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\PrivateFlag;
use Marque\Trove\Models\Torrent;

// #10947: the download is rebuilt for the member — their announce URL, a link
// back to this page — around the stored info dictionary, byte for byte.

function guiseBindPolicy(?string $url): void
{
    app()->instance(TorrentFilePolicyInterface::class, new class($url) implements TorrentFilePolicyInterface
    {
        public function __construct(private ?string $url) {}

        public function privateFlag(): PrivateFlag
        {
            return PrivateFlag::Allow;
        }

        public function announceUrlFor(?UserInterface $user): ?string
        {
            return $this->url;
        }
    });
}

beforeEach(function () {
    Storage::fake('local');
    $this->info = 'd6:lengthi5e4:name4:test12:piece lengthi16384e6:pieces20:'.str_repeat('a', 20).'7:privatei1ee';
    Storage::disk('local')->put('torrents/t.torrent', 'd8:announce17:http://uploader/a13:announce-listll14:http://other/aee4:info'.$this->info.'e');
    $this->torrent = Torrent::factory()->create(['torrent_file' => 'torrents/t.torrent', 'info_hash' => sha1($this->info)]);
    $this->user = TestUser::factory()->create();
});

test('carries the member\'s announce URL and a link to the torrent page, and keeps the info hash', function () {
    guiseBindPolicy('https://tracker.example/announce/MEMBERKEY');

    $response = $this->actingAs($this->user)->get(route('torrents.download', $this->torrent))->assertOk();
    $raw = Bencode::rawDictionary($response->streamedContent());

    expect(array_keys($raw))->toBe(['announce', 'comment', 'info'])
        ->and(Bencode::decode($raw['announce']))->toBe('https://tracker.example/announce/MEMBERKEY')
        ->and(Bencode::decode($raw['comment']))->toBe(route('torrents.show', $this->torrent))
        ->and(sha1($raw['info']))->toBe($this->torrent->info_hash);
});

test('refuses a member the tracker has no announce URL for, and says why', function () {
    guiseBindPolicy(null);

    $this->actingAs($this->user)->get(route('torrents.download', $this->torrent))
        ->assertForbidden()
        ->assertSee('announce key');
});

test('serves the stored file unchanged when no tracker is installed', function () {
    $response = $this->actingAs($this->user)->get(route('torrents.download', $this->torrent))->assertOk();

    expect($response->streamedContent())->toBe(Storage::disk('local')->get('torrents/t.torrent'));
});
