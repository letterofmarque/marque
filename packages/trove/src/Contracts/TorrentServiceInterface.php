<?php

declare(strict_types=1);

namespace Marque\Trove\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Marque\Trove\Models\Torrent;

interface TorrentServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, Torrent>
     */
    public function list(int $perPage = 25, ?string $search = null): LengthAwarePaginator;

    public function find(int $id): ?Torrent;

    public function findByInfoHash(string $infoHash): ?Torrent;

    public function createFromUpload(
        UploadedFile $file,
        Authenticatable $user,
        string $name,
        ?string $description = null,
    ): Torrent;

    /**
     * @param  array{info_hash: string, name: string, description?: string|null, size?: int, file_count?: int, torrent_file?: string|null}  $data
     */
    public function create(array $data, Authenticatable $user): Torrent;

    /**
     * @param  array{name?: string, description?: string|null}  $data
     */
    public function update(Torrent $torrent, array $data): Torrent;

    public function delete(Torrent $torrent): bool;
}
