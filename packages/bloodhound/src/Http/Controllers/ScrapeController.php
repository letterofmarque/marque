<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Marque\Bloodhound\Services\PeerService;
use Marque\Bloodhound\Support\TrackerResponse;
use Marque\Trove\Models\Torrent;

class ScrapeController extends Controller
{
    public function __construct(
        private readonly PeerService $peerService,
    ) {}

    /**
     * Handle scrape request.
     *
     * Returns stats for one or more torrents.
     * URL: /scrape or /scrape/{passkey}
     */
    public function __invoke(Request $request, ?string $passkey = null): Response
    {
        // Passkey is optional for scrape but can be used for private trackers
        if ($passkey !== null && ! preg_match('/^[0-9a-zA-Z]{32}$/', $passkey)) {
            return TrackerResponse::error('Invalid passkey');
        }

        // Get info_hash(es) - can be single or multiple
        $infoHashes = $this->parseInfoHashes($request);

        if (empty($infoHashes)) {
            return TrackerResponse::error('Missing info_hash');
        }

        // Limit number of hashes to prevent abuse
        $maxHashes = 50;
        if (count($infoHashes) > $maxHashes) {
            $infoHashes = array_slice($infoHashes, 0, $maxHashes);
        }

        // Get stats for each torrent
        $files = [];

        foreach ($infoHashes as $infoHash) {
            $torrent = Torrent::where('info_hash', $infoHash)->first();

            if ($torrent === null) {
                continue; // Skip unknown torrents
            }

            // Get live peer counts from Redis
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
     * Handles both single and multiple info_hash parameters.
     *
     * @return array<string>
     */
    private function parseInfoHashes(Request $request): array
    {
        $hashes = [];

        // Get all info_hash values (PHP converts multiple same-name params to array)
        $rawHashes = $request->get('info_hash');

        if ($rawHashes === null) {
            return [];
        }

        // Ensure array
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
        // Check if already hex (40 chars)
        if (strlen($rawHash) === 40 && ctype_xdigit($rawHash)) {
            return strtolower($rawHash);
        }

        // Binary format (20 bytes) - convert to hex
        if (strlen($rawHash) === 20) {
            return strtolower(bin2hex($rawHash));
        }

        return null;
    }
}
