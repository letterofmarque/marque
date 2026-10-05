<?php

declare(strict_types=1);

namespace Marque\Trove\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Models\Torrent;

/**
 * Finds torrents stored under an info_hash their own .torrent file doesn't have.
 *
 * Before trove 4.4, uploads were hashed as sha1(encode(decode(info))), which
 * normalises the info dictionary. A torrent whose info wasn't canonical bencode
 * was stored under a hash no client announces, so it never saw a peer (#10946).
 * This re-hashes each stored file the way clients do and lists the mismatches.
 *
 * Report only. Rewriting an info_hash is the operator's decision: the correct
 * hash may already belong to another row, and anything keyed on the old one
 * (announce logs, links) needs thought first.
 */
class CheckInfoHashes extends Command
{
    protected $signature = 'trove:check-info-hashes {--chunk=500 : Torrents to load per batch}';

    protected $description = 'List torrents whose stored info_hash does not match their .torrent file';

    public function handle(): int
    {
        $disk = Storage::disk(config('trove.storage_disk', 'local'));
        $checked = 0;
        $mismatched = 0;
        $skipped = 0;

        Torrent::query()
            ->whereNotNull('torrent_file')
            ->select(['id', 'info_hash', 'torrent_file'])
            ->chunkById(max(1, (int) $this->option('chunk')), function ($torrents) use ($disk, &$checked, &$mismatched, &$skipped): void {
                foreach ($torrents as $torrent) {
                    if (! $disk->exists($torrent->torrent_file)) {
                        $this->warn("#{$torrent->id}: file missing ({$torrent->torrent_file})");
                        $skipped++;

                        continue;
                    }

                    try {
                        $raw = Bencode::rawDictionary((string) $disk->get($torrent->torrent_file));
                    } catch (InvalidArgumentException $e) {
                        $this->warn("#{$torrent->id}: unreadable .torrent ({$e->getMessage()})");
                        $skipped++;

                        continue;
                    }

                    // Present AND a dictionary: an `info` holding a string or an
                    // integer would otherwise be hashed and reported as a mismatch.
                    if (! str_starts_with($raw['info'] ?? '', 'd')) {
                        $this->warn("#{$torrent->id}: .torrent has no info dictionary");
                        $skipped++;

                        continue;
                    }

                    $actual = sha1($raw['info']);

                    $checked++;

                    if (! hash_equals($torrent->info_hash, $actual)) {
                        $mismatched++;
                        $this->line("#{$torrent->id}: stored {$torrent->info_hash}, file is {$actual}");
                    }
                }
            });

        $this->info("{$mismatched} of {$checked} torrent(s) are stored under the wrong info_hash"
            .($skipped > 0 ? "; {$skipped} skipped (see warnings)." : '.'));

        return $mismatched === 0 ? self::SUCCESS : self::FAILURE;
    }
}
