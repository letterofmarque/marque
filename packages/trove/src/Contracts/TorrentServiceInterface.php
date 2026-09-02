<?php

declare(strict_types=1);

namespace Marque\Trove\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\ViewerScope;

interface TorrentServiceInterface
{
    /**
     * List torrents the viewer is allowed to see.
     *
     * $viewer defaults to the authenticated user rather than to null. Null is
     * a real value here — it means "a guest", and guests see only unrestricted
     * torrents — so an omitted argument must not be mistaken for one, or a
     * caller that forgets to pass a viewer silently gets the guest list
     * instead of the caller's own. Pass null explicitly for genuine guest
     * browsing.
     *
     * @return LengthAwarePaginator<int, Torrent>
     */
    public function list(
        int $perPage = 25,
        ?string $search = null,
        ?ViewerScope $viewer = null,
        bool $includeDead = false,
    ): LengthAwarePaginator;

    /**
     * @see self::list() for $viewer semantics.
     */
    public function find(int $id, ?ViewerScope $viewer = null): ?Torrent;

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
