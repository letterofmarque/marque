<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Support;

use InvalidArgumentException;

/**
 * An IPv4 or IPv6 network in CIDR notation: parsing, normalising, matching.
 */
final class Network
{
    private function __construct(
        private readonly string $packed,
        private readonly int $prefix,
    ) {}

    /**
     * Parse an address or a CIDR range. A bare address is its own /32 or /128.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $network): self
    {
        [$address, $prefix] = array_pad(explode('/', trim($network), 2), 2, null);
        $packed = @inet_pton((string) $address);

        if ($packed === false) {
            throw new InvalidArgumentException("\"{$network}\" is not an IP address or range.");
        }

        $bits = strlen($packed) * 8;

        if ($prefix === null) {
            $prefix = $bits;
        } elseif (! ctype_digit($prefix) || (int) $prefix > $bits) {
            throw new InvalidArgumentException("\"{$network}\" has an invalid prefix length.");
        }

        return new self(self::mask($packed, (int) $prefix), (int) $prefix);
    }

    public function isIpv6(): bool
    {
        return strlen($this->packed) === 16;
    }

    public function prefix(): int
    {
        return $this->prefix;
    }

    /**
     * The network in CIDR notation, from its network address: 203.0.113.0/24.
     */
    public function cidr(): string
    {
        return inet_ntop($this->packed).'/'.$this->prefix;
    }

    public function contains(string $ip): bool
    {
        $packed = @inet_pton($ip);

        return $packed !== false
            && strlen($packed) === strlen($this->packed)
            && self::mask($packed, $this->prefix) === $this->packed;
    }

    private static function mask(string $packed, int $prefix): string
    {
        $masked = '';

        foreach (str_split($packed) as $i => $byte) {
            $keep = max(0, min(8, $prefix - $i * 8));
            $masked .= chr(ord($byte) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return $masked;
    }
}
