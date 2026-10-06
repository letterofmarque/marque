<?php

declare(strict_types=1);

namespace Marque\Guise\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Exceptions\NoAnnounceUrl;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Services\TorrentFileService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TorrentDownloadController
{
    use AuthorizesRequests;

    public function __invoke(Torrent $torrent, TorrentFileService $files): StreamedResponse
    {
        // The download is built for this member, carrying their own announce
        // URL on a private tracker, so it must be gated at least as tightly
        // as viewing, never less.
        $this->authorize('view', $torrent);

        abort_unless(
            $torrent->torrent_file !== null
                && Storage::disk(config('trove.storage_disk', 'local'))->exists($torrent->torrent_file),
            404,
            'No torrent file available.',
        );

        $user = auth()->user();

        try {
            $body = $files->forDownload(
                $torrent,
                $user instanceof UserInterface ? $user : null,
                route('torrents.show', $torrent),
            );
        } catch (NoAnnounceUrl $e) {
            abort(403, $e->getMessage());
        }

        $filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $torrent->name).'.torrent';

        return response()->streamDownload(
            static function () use ($body): void {
                echo $body;
            },
            $filename,
            ['Content-Type' => 'application/x-bittorrent'],
        );
    }
}
