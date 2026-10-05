<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Marque\Threepio\Support\Bencode;
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

        expect($result)->toBeInstanceOf(LengthAwarePaginator::class)
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

// #10946. The info_hash was sha1(encode(decode(info))), which normalises the
// dictionary. Clients hash the bytes as the file holds them, so a torrent whose
// info isn't canonical bencode was stored under a hash nobody announces.
describe('info_hash on upload', function () {
    function uploadTorrent(object $test, string $info): Torrent
    {
        Storage::fake(config('trove.storage_disk', 'local'));

        $file = UploadedFile::fake()->createWithContent(
            'x.torrent',
            'd8:announce13:http://x/ann/4:info'.$info.'e',
        );

        return $test->service->createFromUpload($file, $test->user, 'Upload');
    }

    test('is sha1 of the info dictionary exactly as the file holds it', function () {
        // `name` before `length`: legal in the wild, not canonical.
        $info = 'd4:name4:test6:lengthi5e12:piece lengthi16384e6:pieces20:'.str_repeat('a', 20).'e';

        $torrent = uploadTorrent($this, $info);

        expect($torrent->info_hash)->toBe(sha1($info))
            ->and($torrent->size)->toBe(5);
    });

    test('is unchanged for a canonical torrent', function () {
        $info = 'd6:lengthi5e4:name4:test12:piece lengthi16384e6:pieces20:'.str_repeat('a', 20).'e';

        expect(uploadTorrent($this, $info)->info_hash)->toBe(sha1($info));
    });
});

describe('trove:check-info-hashes', function () {
    function storeTorrent(object $test, string $info, string $storedHash): Torrent
    {
        $disk = config('trove.storage_disk', 'local');
        $path = 'torrents/'.$storedHash.'.torrent';
        Storage::disk($disk)->put($path, 'd4:info'.$info.'e');

        return Torrent::create([
            'info_hash' => $storedHash,
            'name' => 'T '.$storedHash,
            'user_id' => $test->user->id,
            'torrent_file' => $path,
        ]);
    }

    test('lists torrents stored under a hash their file does not have, and changes nothing', function () {
        Storage::fake(config('trove.storage_disk', 'local'));
        $bad = 'd4:name1:x6:lengthi1ee';
        $good = 'd6:lengthi1e4:name1:ye';
        $wrong = storeTorrent($this, $bad, sha1(Bencode::encode(Bencode::decode($bad))));
        storeTorrent($this, $good, sha1($good));

        $this->artisan('trove:check-info-hashes')
            ->expectsOutputToContain("#{$wrong->id}: stored {$wrong->info_hash}, file is ".sha1($bad))
            ->expectsOutputToContain('1 of 2')
            ->assertExitCode(1);

        expect($wrong->fresh()->info_hash)->not->toBe(sha1($bad));
    });

    test('warns about and skips a file with no info dictionary, a missing file, or bad bencode', function () {
        Storage::fake(config('trove.storage_disk', 'local'));
        $disk = Storage::disk(config('trove.storage_disk', 'local'));
        $disk->put('torrents/noinfo.torrent', 'd4:infoi1ee'); // an info that isn't a dictionary
        $disk->put('torrents/bad.torrent', 'not bencode');
        foreach (['noinfo', 'bad', 'gone'] as $i => $name) {
            Torrent::create(['info_hash' => str_repeat((string) $i, 40), 'name' => $name, 'user_id' => $this->user->id, 'torrent_file' => "torrents/{$name}.torrent"]);
        }

        $this->artisan('trove:check-info-hashes')
            ->expectsOutputToContain('no info dictionary')
            ->expectsOutputToContain('unreadable')
            ->expectsOutputToContain('file missing')
            ->expectsOutputToContain('0 of 0 torrent(s) are stored under the wrong info_hash; 3 skipped')
            ->assertExitCode(0);
    });

    test('exits 0 when every stored hash matches its file', function () {
        Storage::fake(config('trove.storage_disk', 'local'));
        $good = 'd6:lengthi1e4:name1:ye';
        storeTorrent($this, $good, sha1($good));

        $this->artisan('trove:check-info-hashes')->assertExitCode(0);
    });
});
