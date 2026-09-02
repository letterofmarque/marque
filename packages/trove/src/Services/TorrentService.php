<?php

declare(strict_types=1);

namespace Marque\Trove\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Marque\Threepio\Support\Bencode;
use Marque\Trove\Contracts\TorrentServiceInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\ViewerScope;

class TorrentService implements TorrentServiceInterface
{
    /**
     * Get paginated list of torrents.
     *
     * Access filtering happens here rather than only in TorrentPolicy: a
     * policy guards a single record, and would leave restricted torrents
     * visible in every index, API collection and count. Because guise,
     * disguise and cennad all read through this one method, filtering here
     * covers all three at once.
     *
     * @return LengthAwarePaginator<int, Torrent>
     */
    public function list(
        int $perPage = 25,
        ?string $search = null,
        ?ViewerScope $viewer = null,
        bool $includeDead = false,
    ): LengthAwarePaginator {
        $viewer ??= ViewerScope::current();

        // Hiding dead torrents is opt-in: a torrent has no seeders until its
        // first announce, so filtering by default would hide fresh uploads and
        // empty the catalogue of any install that has just upgraded.
        $hideDead = config('trove.hide_dead_torrents', false) && ! $includeDead;

        return Torrent::with('user')
            ->visibleTo($viewer->user)
            ->when($hideDead, fn ($query) => $query->alive())
            ->when($search, fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%']))
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get a single torrent by ID.
     *
     * Dead torrents are still returned — a direct link to one should show the
     * torrent, not a 404. Only the listing hides them.
     */
    public function find(int $id, ?ViewerScope $viewer = null): ?Torrent
    {
        $viewer ??= ViewerScope::current();

        return Torrent::with('user')
            ->visibleTo($viewer->user)
            ->find($id);
    }

    /**
     * Get a torrent by info_hash.
     */
    public function findByInfoHash(string $infoHash): ?Torrent
    {
        return Torrent::with('user')
            ->where('info_hash', strtolower($infoHash))
            ->first();
    }

    /**
     * Create a new torrent from uploaded file.
     */
    public function createFromUpload(
        UploadedFile $file,
        Authenticatable $user,
        string $name,
        ?string $description = null
    ): Torrent {
        $torrentData = $this->parseTorrentFile($file);

        // Store the .torrent file
        $path = $file->store('torrents', config('trove.storage_disk', 'local'));

        return Torrent::create([
            'info_hash' => $torrentData['info_hash'],
            'name' => $name,
            'description' => $description,
            'size' => $torrentData['size'],
            'file_count' => $torrentData['file_count'],
            'torrent_file' => $path,
            'user_id' => $user->getAuthIdentifier(),
        ]);
    }

    /**
     * Create a torrent manually (without file upload).
     *
     * @param  array{info_hash: string, name: string, description?: string|null, size?: int, file_count?: int, torrent_file?: string|null}  $data
     */
    public function create(array $data, Authenticatable $user): Torrent
    {
        return Torrent::create([
            'info_hash' => strtolower($data['info_hash']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'size' => $data['size'] ?? 0,
            'file_count' => $data['file_count'] ?? 1,
            'torrent_file' => $data['torrent_file'] ?? null,
            'user_id' => $user->getAuthIdentifier(),
        ]);
    }

    /**
     * Update a torrent.
     *
     * @param  array{name?: string, description?: string|null}  $data
     */
    public function update(Torrent $torrent, array $data): Torrent
    {
        $torrent->update([
            'name' => $data['name'] ?? $torrent->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $torrent->description,
        ]);

        return $torrent->fresh();
    }

    /**
     * Delete a torrent.
     */
    public function delete(Torrent $torrent): bool
    {
        // Delete the stored .torrent file if it exists
        if ($torrent->torrent_file) {
            Storage::disk(config('trove.storage_disk', 'local'))->delete($torrent->torrent_file);
        }

        return $torrent->delete();
    }

    /**
     * Parse a .torrent file and extract metadata.
     *
     * @return array{info_hash: string, size: int, file_count: int, name: string}
     */
    protected function parseTorrentFile(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        $decoded = Bencode::decode($content);

        if (! isset($decoded['info'])) {
            throw new \InvalidArgumentException('Invalid torrent file: missing info dictionary');
        }

        $info = $decoded['info'];

        // Calculate info_hash (SHA1 of bencoded info dictionary)
        $infoHash = sha1(Bencode::encode($info));

        // Calculate total size
        $size = 0;
        $fileCount = 1;

        if (isset($info['files'])) {
            // Multi-file torrent
            $fileCount = count($info['files']);
            foreach ($info['files'] as $f) {
                $size += $f['length'];
            }
        } else {
            // Single file torrent
            $size = $info['length'] ?? 0;
        }

        return [
            'info_hash' => $infoHash,
            'size' => $size,
            'file_count' => $fileCount,
            'name' => $info['name'] ?? 'Unknown',
        ];
    }
}
