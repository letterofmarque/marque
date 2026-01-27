<?php

declare(strict_types=1);

namespace Marque\Guise\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Marque\Trove\Models\Torrent;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TorrentDownloadController
{
    public function __invoke(Torrent $torrent): StreamedResponse
    {
        abort_unless($torrent->torrent_file !== null, 404, 'No torrent file available.');

        $filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $torrent->name).'.torrent';

        return Storage::disk(config('trove.storage_disk', 'local'))->download($torrent->torrent_file, $filename);
    }
}
