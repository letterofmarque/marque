<?php

declare(strict_types=1);

namespace Marque\Hound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Marque\Hound\Services\AnnounceService;
use Marque\Threepio\Support\PeerAddresses;
use Marque\Threepio\Support\RawQuery;
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
        $infoHash = $this->parseInfoHash(RawQuery::of($request)->first('info_hash') ?? '');
        if ($infoHash === null) {
            return TrackerResponse::error('Invalid info_hash');
        }

        $torrent = Torrent::where('info_hash', $infoHash)->first();
        if ($torrent === null) {
            return TrackerResponse::error('Torrent not registered');
        }

        // Get peer_id
        $peerId = (string) RawQuery::of($request)->first('peer_id');
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

        [$ipv4, $ipv6] = $this->announcedAddresses($request, $ip);

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
            ipv4: $ipv4,
            ipv6: $ipv6,
        );
    }

    /**
     * The addresses the client says it has besides the one it connected from
     * (BEP 7's `ipv4=` and `ipv6=`).
     *
     * Ignored unless `hound.trust_announced_addresses` is on. Nobody answers
     * for an announce here, so honouring them lets anyone put any public
     * address into a swarm's peer lists and aim its traffic at it (the old
     * `ip=` reflection). Dual-stack clients still reach both families: they
     * announce over each in turn, and the peer keeps an address in each.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function announcedAddresses(Request $request, string $ip): array
    {
        if (! config('hound.trust_announced_addresses', false)) {
            return [null, null];
        }

        $publicOnly = app()->environment('production');
        $connected = PeerAddresses::family($ip);

        // Only the family the announce didn't come over: the address it came
        // from is what the tracker saw, and a claim for that family is unused.
        return [
            $connected === 4 ? null : PeerAddresses::announced($request->query('ipv4'), 4, $publicOnly),
            $connected === 6 ? null : PeerAddresses::announced($request->query('ipv6'), 6, $publicOnly),
        ];
    }

    /**
     * Validate required request parameters.
     */
    private function validateRequest(Request $request): ?Response
    {
        $required = ['info_hash', 'peer_id', 'port', 'uploaded', 'downloaded', 'left'];

        // From the raw query string: info_hash and peer_id are binary, and
        // the trimmed copies in $request can be shorter, or empty, or gone.
        $raw = RawQuery::of($request);

        foreach ($required as $param) {
            if ($raw->first($param) === null && ! $request->has($param)) {
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

        // A dual-stack listener reports an IPv4 client as ::ffff:a.b.c.d; it
        // is an IPv4 peer, and goes in `peers`, not `peers6`.
        $ip = PeerAddresses::normalise($ip);

        if (app()->environment('production')) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $ip;
    }
}
