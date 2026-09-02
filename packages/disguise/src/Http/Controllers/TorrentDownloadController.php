<?php

declare(strict_types=1);

namespace Marque\Disguise\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Marque\Trove\Models\Torrent;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TorrentDownloadController
{
    use AuthorizesRequests;

    public function __invoke(Torrent $torrent): StreamedResponse
    {
        abort_unless(config('disguise.allow_download', true), 404);

        // Gated at least as tightly as viewing — the .torrent carries the
        // announce key.
        $this->authorize('view', $torrent);
        abort_unless($torrent->torrent_file !== null, 404, 'No torrent file available.');

        $filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $torrent->name).'.torrent';

        return Storage::disk(config('trove.storage_disk', 'local'))->download($torrent->torrent_file, $filename);
    }
}
