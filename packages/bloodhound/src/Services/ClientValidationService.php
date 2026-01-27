<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

/**
 * Validates BitTorrent clients based on peer_id.
 *
 * Supports whitelist mode (only allow known clients) or
 * blacklist mode (block known bad clients).
 */
final class ClientValidationService
{
    /**
     * Validate a client by its peer_id.
     *
     * @return array{valid: bool, client: ?string, version: ?string, reason: ?string}
     */
    public function validate(string $peerId): array
    {
        if (! config('bloodhound.client_validation.enabled', true)) {
            return [
                'valid' => true,
                'client' => null,
                'version' => null,
                'reason' => null,
            ];
        }

        $mode = config('bloodhound.client_validation.mode', 'whitelist');

        return $mode === 'whitelist'
            ? $this->validateWhitelist($peerId)
            : $this->validateBlacklist($peerId);
    }

    /**
     * Identify client from peer_id without validating.
     *
     * @return array{client: ?string, version: ?string}
     */
    public function identify(string $peerId): array
    {
        $whitelist = config('bloodhound.whitelist', []);

        foreach ($whitelist as $clientName => $config) {
            $result = $this->matchClient($peerId, $clientName, $config);

            if ($result['matched']) {
                return [
                    'client' => $clientName,
                    'version' => $result['version'],
                ];
            }
        }

        return [
            'client' => $this->guessClientFromPeerId($peerId),
            'version' => null,
        ];
    }

    /**
     * Validate against whitelist.
     */
    private function validateWhitelist(string $peerId): array
    {
        $whitelist = config('bloodhound.whitelist', []);

        foreach ($whitelist as $clientName => $config) {
            $result = $this->matchClient($peerId, $clientName, $config);

            if (! $result['matched']) {
                continue;
            }

            // Client matched, now check version
            if ($result['version'] === null) {
                return [
                    'valid' => true,
                    'client' => $clientName,
                    'version' => 'unknown',
                    'reason' => null,
                ];
            }

            // Check blocked versions
            $blockedVersions = $config['blocked_versions'] ?? [];
            if (in_array($result['version'], $blockedVersions, true)) {
                return [
                    'valid' => false,
                    'client' => $clientName,
                    'version' => $result['version'],
                    'reason' => "Version {$result['version']} of {$clientName} is blocked",
                ];
            }

            // Check min version
            $minVersion = $config['min_version'] ?? null;
            if ($minVersion !== null && version_compare($result['version'], $minVersion, '<')) {
                return [
                    'valid' => false,
                    'client' => $clientName,
                    'version' => $result['version'],
                    'reason' => "{$clientName} version {$result['version']} is too old (minimum: {$minVersion})",
                ];
            }

            // Check max version
            $maxVersion = $config['max_version'] ?? null;
            if ($maxVersion !== null && version_compare($result['version'], $maxVersion, '>')) {
                return [
                    'valid' => false,
                    'client' => $clientName,
                    'version' => $result['version'],
                    'reason' => "{$clientName} version {$result['version']} is too new (maximum: {$maxVersion})",
                ];
            }

            return [
                'valid' => true,
                'client' => $clientName,
                'version' => $result['version'],
                'reason' => null,
            ];
        }

        // No match found in whitelist
        return [
            'valid' => false,
            'client' => $this->guessClientFromPeerId($peerId),
            'version' => null,
            'reason' => 'Client not in whitelist',
        ];
    }

    /**
     * Validate against blacklist.
     */
    private function validateBlacklist(string $peerId): array
    {
        $blacklist = config('bloodhound.blacklist', []);

        foreach ($blacklist as $pattern => $reason) {
            if (str_starts_with($peerId, $pattern)) {
                return [
                    'valid' => false,
                    'client' => $this->guessClientFromPeerId($peerId),
                    'version' => null,
                    'reason' => $reason,
                ];
            }
        }

        // Identify the client for logging purposes
        $identified = $this->identify($peerId);

        return [
            'valid' => true,
            'client' => $identified['client'],
            'version' => $identified['version'],
            'reason' => null,
        ];
    }

    /**
     * Match a peer_id against a client configuration.
     *
     * @return array{matched: bool, version: ?string}
     */
    private function matchClient(string $peerId, string $clientName, array $config): array
    {
        $pattern = $config['peer_id_pattern'] ?? null;

        if ($pattern === null) {
            return ['matched' => false, 'version' => null];
        }

        if (! preg_match($pattern, $peerId, $matches)) {
            return ['matched' => false, 'version' => null];
        }

        // Extract version from matches
        $version = $this->extractVersion($matches, $config['version_format'] ?? null);

        return ['matched' => true, 'version' => $version];
    }

    /**
     * Extract version string from regex matches.
     */
    private function extractVersion(array $matches, ?string $format): ?string
    {
        if (count($matches) < 2) {
            return null;
        }

        // Remove full match, keep capture groups
        array_shift($matches);

        // Convert hex characters to decimal if needed
        $parts = array_map(function ($part) {
            if (ctype_xdigit($part) && ! ctype_digit($part)) {
                return hexdec($part);
            }

            return (int) $part;
        }, $matches);

        if ($format === null) {
            return implode('.', $parts);
        }

        // Use sprintf format
        return vsprintf($format, $parts);
    }

    /**
     * Attempt to identify client from peer_id prefix.
     */
    private function guessClientFromPeerId(string $peerId): ?string
    {
        // Azureus-style: -XX####-
        if (preg_match('/^-([A-Za-z]{2})/', $peerId, $matches)) {
            return $this->azureusCodeToName($matches[1]);
        }

        // Shadow-style: first char is client
        $shadowClients = [
            'A' => 'ABC',
            'M' => 'Mainline',
            'O' => 'Osprey',
            'Q' => 'BTQueue',
            'R' => 'Tribler',
            'S' => 'Shadow',
            'T' => 'BitTornado',
        ];

        if (isset($shadowClients[$peerId[0]])) {
            return $shadowClients[$peerId[0]];
        }

        return null;
    }

    /**
     * Convert Azureus-style two-letter code to client name.
     */
    private function azureusCodeToName(string $code): string
    {
        $codes = [
            'AG' => 'Ares',
            'AZ' => 'Vuze',
            'BG' => 'BiglyBT',
            'BT' => 'BitTorrent',
            'DE' => 'Deluge',
            'lt' => 'libtorrent',
            'LT' => 'libtorrent',
            'qB' => 'qBittorrent',
            'RT' => 'rTorrent',
            'TR' => 'Transmission',
            'UT' => 'uTorrent',
            'XL' => 'Xunlei',
        ];

        return $codes[$code] ?? "Unknown ({$code})";
    }
}
