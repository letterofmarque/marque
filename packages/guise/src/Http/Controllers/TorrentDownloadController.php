<?php

declare(strict_types=1);

namespace Marque\Guise\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Marque\Trove\Models\Torrent;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TorrentDownloadController
{
    use AuthorizesRequests;

    public function __invoke(Torrent $torrent): StreamedResponse
    {
        // Downloading hands over the .torrent, which carries the announce key
        // — so this must be gated at least as tightly as viewing, never less.
        $this->authorize('view', $torrent);

        abort_unless($torrent->torrent_file !== null, 404, 'No torrent file available.');

        $filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $torrent->name).'.torrent';

        return Storage::disk(config('trove.storage_disk', 'local'))->download($torrent->torrent_file, $filename);
    }
}
