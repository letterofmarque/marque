<?php

declare(strict_types=1);

namespace Marque\Hound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Marque\Hound\Services\AnnounceService;
use Marque\Threepio\Support\TrackerResponse;
use Marque\Trove\Models\Torrent;

class AnnounceController extends Controller
{
    public function __construct(
        private readonly AnnounceService $announceService,
    ) {}

    /**
     * Handle public announce request.
     *
     * URL: /announce (no announce key)
     */
    public function __invoke(Request $request): Response
    {
        // Validate required parameters
        $validation = $this->validateRequest($request);
        if ($validation !== null) {
            return $validation;
        }

        // Get info_hash and find torrent
        $infoHash = $this->parseInfoHash($request->get('info_hash'));
        if ($infoHash === null) {
            return TrackerResponse::error('Invalid info_hash');
        }

        $torrent = Torrent::where('info_hash', $infoHash)->first();
        if ($torrent === null) {
            return TrackerResponse::error('Torrent not registered');
        }

        // Get peer_id
        $peerId = $request->get('peer_id');
        if (strlen($peerId) !== 20) {
            return TrackerResponse::error('Invalid peer_id length');
        }

        // Parse numeric parameters
        $port = (int) $request->get('port', 0);
        $uploaded = (int) $request->get('uploaded', 0);
        $downloaded = (int) $request->get('downloaded', 0);
        $left = (int) $request->get('left', 0);
        $numWant = (int) ($request->get('numwant') ?? $request->get('num_want') ?? 50);
        $compact = $request->get('compact', '1') === '1';
        $event = $request->get('event');

        // Get IP
        $ip = $this->getClientIp($request);
        if ($ip === null) {
            return TrackerResponse::error('Invalid IP');
        }

        return $this->announceService->handle(
            torrent: $torrent,
            peerId: $peerId,
            ip: $ip,
            port: $port,
            uploaded: $uploaded,
            downloaded: $downloaded,
            left: $left,
            event: $event,
            compact: $compact,
            numWant: $numWant,
        );
    }

    /**
     * Validate required request parameters.
     */
    private function validateRequest(Request $request): ?Response
    {
        $required = ['info_hash', 'peer_id', 'port', 'uploaded', 'downloaded', 'left'];

        foreach ($required as $param) {
            if (! $request->has($param)) {
                return TrackerResponse::error("Missing parameter: {$param}");
            }
        }

        $port = (int) $request->get('port');
        if ($port <= 0 || $port > 65535) {
            return TrackerResponse::error('Invalid port');
        }

        return null;
    }

    /**
     * Parse info_hash from request (may be URL-encoded binary).
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

    /**
     * Get client IP address.
     */
    private function getClientIp(Request $request): ?string
    {
        $ip = $request->ip();

        if ($ip === null || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (app()->environment('production')) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $ip;
    }
}
