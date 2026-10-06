<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\PrivateFlag;
use Marque\Trove\Exceptions\NoAnnounceUrl;
use Marque\Trove\Exceptions\TorrentRefused;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Services\TorrentFileService;
use Marque\Trove\Services\TorrentService;
use Marque\Trove\Tests\TestUser;

// #10947. The stored .torrent is the uploader's original and its info
// dictionary is never touched; uploads are refused, never rewritten, when they
// break the tracker's private-flag rule; downloads rebuild only the top level.

function torrentBytes(bool $private = false, array $extraTop = [], array $extraInfo = []): string
{
    $info = array_merge([
        'length' => 5,
        'name' => 'test',
        'piece length' => 16384,
        'pieces' => str_repeat('a', 20),
    ], $private ? ['private' => 1] : [], $extraInfo);

    return Bencode::encode(array_merge(['announce' => 'http://uploader.example/announce', 'info' => $info], $extraTop));
}

function bindPolicy(PrivateFlag $flag, ?string $url = 'https://tracker.example/announce/KEY'): void
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
    $this->user = TestUser::factory()->create();
    $this->files = app(TorrentFileService::class);
});

describe('inspecting an upload against the private-flag policy', function () {
    test('applies each mode', function (PrivateFlag $flag, bool $private, bool $refused, bool $warned) {
        bindPolicy($flag);

        $inspection = $this->files->inspect(torrentBytes(private: $private));

        expect($inspection->refused())->toBe($refused)
            ->and($inspection->warnings !== [])->toBe($warned);
    })->with([
        'allow, public' => [PrivateFlag::Allow, false, false, false],
        'allow, private' => [PrivateFlag::Allow, true, false, false],
        'require, public' => [PrivateFlag::Require, false, true, false],
        'require, private' => [PrivateFlag::Require, true, false, false],
        'disallow, private' => [PrivateFlag::Disallow, true, true, false],
        'disallow, public' => [PrivateFlag::Disallow, false, false, false],
        'warn_if_public, public' => [PrivateFlag::WarnIfPublic, false, false, true],
        'warn_if_public, private' => [PrivateFlag::WarnIfPublic, true, false, false],
        'warn_if_private, private' => [PrivateFlag::WarnIfPrivate, true, false, true],
        'warn_if_private, public' => [PrivateFlag::WarnIfPrivate, false, false, false],
    ]);

    test('a refusal names the fix', function () {
        bindPolicy(PrivateFlag::Require);
        expect($this->files->inspect(torrentBytes())->refusals[0])->toContain('private')->toContain('ticked');

        bindPolicy(PrivateFlag::Disallow);
        expect($this->files->inspect(torrentBytes(private: true))->refusals[0])->toContain('unticked');
    });

    test('with no tracker policy bound, anything goes', function () {
        expect($this->files->inspect(torrentBytes())->refused())->toBeFalse();
    });

    test('refuses a v2-only torrent and warns about a hybrid', function () {
        $v2 = Bencode::encode(['info' => ['meta version' => 2, 'name' => 'x', 'piece length' => 16384, 'file tree' => ['x' => ['' => ['length' => 1]]]]]);
        $hybrid = torrentBytes(extraInfo: ['meta version' => 2, 'file tree' => ['test' => ['' => ['length' => 5]]]]);

        expect($this->files->inspect($v2)->refused())->toBeTrue()
            ->and($this->files->inspect($hybrid)->refused())->toBeFalse()
            ->and($this->files->inspect($hybrid)->warnings)->not->toBe([]);
    });

    test('refuses something that is not a .torrent', function () {
        expect($this->files->inspect('not bencode')->refused())->toBeTrue()
            ->and($this->files->inspect('li1ee')->refused())->toBeTrue();
    });
});

describe('uploading', function () {
    test('a refused torrent is not stored, and says why', function () {
        Storage::fake(config('trove.storage_disk', 'local'));
        bindPolicy(PrivateFlag::Require);
        $file = UploadedFile::fake()->createWithContent('x.torrent', torrentBytes());

        expect(fn () => app(TorrentService::class)->createFromUpload($file, $this->user, 'X'))
            ->toThrow(TorrentRefused::class)
            ->and(Torrent::count())->toBe(0);
    });

    test('an accepted torrent is stored byte-for-byte, extra top-level keys and all', function () {
        Storage::fake(config('trove.storage_disk', 'local'));
        bindPolicy(PrivateFlag::Require);
        $bytes = torrentBytes(private: true, extraTop: ['announce-list' => [['http://other/a']], 'nodes' => [['1.2.3.4', 6881]]]);
        $file = UploadedFile::fake()->createWithContent('x.torrent', $bytes);

        $torrent = app(TorrentService::class)->createFromUpload($file, $this->user, 'X');

        expect(Storage::disk(config('trove.storage_disk', 'local'))->get($torrent->torrent_file))->toBe($bytes);
    });
});

describe('building a download', function () {
    function storedTorrent(object $test, string $bytes): Torrent
    {
        Storage::fake(config('trove.storage_disk', 'local'));
        $file = UploadedFile::fake()->createWithContent('x.torrent', $bytes);

        return app(TorrentService::class)->createFromUpload($file, $test->user, 'X');
    }

    test('with no tracker policy bound, serves the stored file unchanged', function () {
        $bytes = torrentBytes(extraTop: ['announce-list' => [['http://other/a']]]);
        $torrent = storedTorrent($this, $bytes);

        expect($this->files->forDownload($torrent, $this->user, 'https://site.example/torrents/1'))->toBe($bytes);
    });

    test('keeps only info and encoding, sets announce and comment, and leaves the info bytes alone', function () {
        // Out-of-order info keys: re-encoding would change them, the splice must not.
        $info = 'd4:name4:test6:lengthi5e12:piece lengthi16384e6:pieces20:'.str_repeat('a', 20).'7:privatei1ee';
        $bytes = 'd8:announce24:http://uploader/announce13:announce-listll14:http://other/aee8:encoding5:UTF-84:info'.$info.'5:nodesle8:url-list14:http://seed/x/e';
        bindPolicy(PrivateFlag::Require);
        $torrent = storedTorrent($this, $bytes);

        $download = $this->files->forDownload($torrent, $this->user, 'https://site.example/torrents/1');
        $raw = Bencode::rawDictionary($download);

        expect(array_keys($raw))->toBe(['announce', 'comment', 'encoding', 'info'])
            ->and(Bencode::decode($raw['announce']))->toBe('https://tracker.example/announce/KEY')
            ->and(Bencode::decode($raw['comment']))->toBe('https://site.example/torrents/1')
            ->and($raw['info'])->toBe($info)
            ->and(sha1($raw['info']))->toBe($torrent->info_hash);
    });

    test('leaves out the comment when the caller has no page to point at', function () {
        bindPolicy(PrivateFlag::Allow);
        $torrent = storedTorrent($this, torrentBytes());

        expect(array_keys(Bencode::rawDictionary($this->files->forDownload($torrent, $this->user))))->toBe(['announce', 'info']);
    });

    test('refuses when the tracker has no announce URL for this user, rather than handing out a key-less file', function () {
        bindPolicy(PrivateFlag::Allow, url: null);
        $torrent = storedTorrent($this, torrentBytes());

        expect(fn () => $this->files->forDownload($torrent, $this->user))->toThrow(NoAnnounceUrl::class);
    });
});
