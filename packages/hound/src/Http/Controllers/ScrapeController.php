<?php

declare(strict_types=1);

namespace Marque\Hound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Marque\Threepio\Services\PeerService;
use Marque\Threepio\Support\TrackerResponse;
use Marque\Trove\Models\Torrent;

class ScrapeController extends Controller
{
    public function __construct(
        private readonly PeerService $peerService,
    ) {}

    /**
     * Handle public scrape request.
     *
     * URL: /scrape (no passkey)
     */
    public function __invoke(Request $request): Response
    {
        $infoHashes = $this->parseInfoHashes($request);

        if (empty($infoHashes)) {
            return TrackerResponse::error('Missing info_hash');
        }

        $maxHashes = 50;
        if (count($infoHashes) > $maxHashes) {
            $infoHashes = array_slice($infoHashes, 0, $maxHashes);
        }

        $files = [];

        foreach ($infoHashes as $infoHash) {
            $torrent = Torrent::where('info_hash', $infoHash)->first();

            if ($torrent === null) {
                continue;
            }

            $seeders = $this->peerService->getSeeders($torrent->id);
            $leechers = $this->peerService->getLeechers($torrent->id);

            $files[$infoHash] = [
                'complete' => $seeders,
                'downloaded' => $torrent->times_completed ?? 0,
                'incomplete' => $leechers,
            ];
        }

        return TrackerResponse::scrape($files);
    }

    /**
     * Parse info_hash(es) from request.
     *
     * @return array<string>
     */
    private function parseInfoHashes(Request $request): array
    {
        $hashes = [];
        $rawHashes = $request->get('info_hash');

        if ($rawHashes === null) {
            return [];
        }

        if (! is_array($rawHashes)) {
            $rawHashes = [$rawHashes];
        }

        foreach ($rawHashes as $rawHash) {
            $parsed = $this->parseInfoHash($rawHash);
            if ($parsed !== null) {
                $hashes[] = $parsed;
            }
        }

        return array_unique($hashes);
    }

    /**
     * Parse a single info_hash (may be binary or hex).
     */
    private function parseInfoHash(string $rawHash): ?string
    {
        if (strlen($rawHash) === 40 && ctype_xdigit($rawHash)) {
            return strtolower($rawHash);
        }

        if (strlen($rawHash) === 20) {
            return strtolower(bin2hex($rawHash));
        }

        return null;
    }
}
