<?php

declare(strict_types=1);

use Marque\Trove\Models\Torrent;
use Marque\Trove\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

describe('Torrent Model', function () {
    test('can create a torrent', function () {
        $torrent = Torrent::create([
            'info_hash' => str_repeat('a', 40),
            'name' => 'Test Torrent',
            'description' => 'A test torrent',
            'size' => 1000000,
            'file_count' => 1,
            'user_id' => $this->user->id,
        ]);

        expect($torrent)->toBeInstanceOf(Torrent::class)
            ->and($torrent->name)->toBe('Test Torrent')
            ->and($torrent->info_hash)->toBe(str_repeat('a', 40));
    });

    test('belongs to a user', function () {
        $torrent = Torrent::create([
            'info_hash' => str_repeat('b', 40),
            'name' => 'Test Torrent',
            'size' => 1000000,
            'user_id' => $this->user->id,
        ]);

        expect($torrent->user)->toBeInstanceOf(TestUser::class)
            ->and($torrent->user->id)->toBe($this->user->id);
    });

    test('sizeForHumans returns formatted size', function () {
        $testCases = [
            [0, '0 B'],
            [500, '500 B'],
            [1024, '1 KB'],
            [1048576, '1 MB'],
            [1073741824, '1 GB'],
            [1099511627776, '1 TB'],
        ];

        foreach ($testCases as [$size, $expected]) {
            $torrent = Torrent::create([
                'info_hash' => bin2hex(random_bytes(20)),
                'name' => 'Size Test',
                'size' => $size,
                'user_id' => $this->user->id,
            ]);

            expect($torrent->sizeForHumans())->toBe($expected);
        }
    });

    test('info_hash must be unique', function () {
        Torrent::create([
            'info_hash' => str_repeat('d', 40),
            'name' => 'First Torrent',
            'user_id' => $this->user->id,
        ]);

        expect(fn () => Torrent::create([
            'info_hash' => str_repeat('d', 40),
            'name' => 'Second Torrent',
            'user_id' => $this->user->id,
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    test('hasTorrentFile returns correct value', function () {
        $withFile = Torrent::create([
            'info_hash' => str_repeat('e', 40),
            'name' => 'With File',
            'torrent_file' => 'some binary data',
            'user_id' => $this->user->id,
        ]);

        $withoutFile = Torrent::create([
            'info_hash' => str_repeat('f', 40),
            'name' => 'Without File',
            'user_id' => $this->user->id,
        ]);

        expect($withFile->hasTorrentFile())->toBeTrue();
        expect($withoutFile->hasTorrentFile())->toBeFalse();
    });
});
