<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Marque\Bloodhound\Services\AnnounceService;
use Marque\Bloodhound\Support\TrackerResponse;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Models\Torrent;

class AnnounceController extends Controller
{
    public function __construct(
        private readonly AnnounceService $announceService,
    ) {}

    /**
     * Handle announce request.
     *
     * URL: /announce/{passkey}
     */
    public function __invoke(Request $request, string $passkey): Response
    {
        // Validate passkey format (alphanumeric, 32 chars)
        if (! preg_match('/^[0-9a-zA-Z]{32}$/', $passkey)) {
            return TrackerResponse::error('Invalid passkey');
        }

        // Find user by passkey
        $user = $this->findUserByPasskey($passkey);
        if ($user === null) {
            return TrackerResponse::error('Unknown passkey');
        }

        // Check if user is enabled
        if (! $this->isUserEnabled($user)) {
            return TrackerResponse::error('Account disabled');
        }

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

        // Check if torrent is banned
        if ($torrent->banned ?? false) {
            return TrackerResponse::error('Torrent is banned');
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

        // Get IP (use X-Forwarded-For if behind proxy, with validation)
        $ip = $this->getClientIp($request);
        if ($ip === null) {
            return TrackerResponse::error('Invalid IP');
        }

        // Get user agent
        $userAgent = $request->userAgent() ?? '';

        return $this->announceService->handle(
            user: $user,
            torrent: $torrent,
            peerId: $peerId,
            infoHash: $infoHash,
            ip: $ip,
            port: $port,
            uploaded: $uploaded,
            downloaded: $downloaded,
            left: $left,
            event: $event,
            userAgent: $userAgent,
            compact: $compact,
            numWant: $numWant,
        );
    }

    /**
     * Find user by passkey.
     */
    private function findUserByPasskey(string $passkey): ?UserInterface
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');

        return $userModel::where('passkey', $passkey)->first();
    }

    /**
     * Check if user is enabled.
     */
    private function isUserEnabled(UserInterface $user): bool
    {
        // Check for common 'enabled' column patterns
        if (property_exists($user, 'enabled')) {
            return $user->enabled === true || $user->enabled === 'yes' || $user->enabled === 1;
        }

        if (property_exists($user, 'status')) {
            return $user->status === 'active' || $user->status === 'enabled';
        }

        // Assume enabled if no status field
        return true;
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

        // Validate port
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

    /**
     * Get client IP address.
     */
    private function getClientIp(Request $request): ?string
    {
        // Get IP from request (Laravel handles X-Forwarded-For if trusted proxies configured)
        $ip = $request->ip();

        // Validate it's a proper IP
        if ($ip === null || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Don't allow private/reserved IPs (unless in development)
        if (app()->environment('production')) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $ip;
    }
}
