<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use InvalidArgumentException;

/**
 * What the interview decided, carried from marque:install to the fresh
 * process that finishes it (Job #131).
 *
 * Encoded as base64 JSON because it travels as a command-line argument. That
 * also means it's untrusted input: decoding rebuilds the selection through
 * PackageSelection, which accepts only the packages the installer offers.
 */
final class InstallAnswers
{
    public function __construct(
        public readonly PackageSelection $selection,
        public readonly ?string $adminName,
        public readonly ?string $adminEmail,
    ) {}

    public function encode(): string
    {
        return base64_encode((string) json_encode([
            'private' => $this->selection->isPrivate(),
            'extras' => $this->selection->extras(),
            'admin_name' => $this->adminName,
            'admin_email' => $this->adminEmail,
        ], JSON_THROW_ON_ERROR));
    }

    public static function decode(string $encoded): self
    {
        $data = json_decode((string) base64_decode($encoded, true), true);

        if (! is_array($data) || ! is_bool($data['private'] ?? null) || ! is_array($data['extras'] ?? null)) {
            throw new InvalidArgumentException('Not answers marque:install wrote.');
        }

        $name = $data['admin_name'] ?? null;
        $email = $data['admin_email'] ?? null;

        return new self(
            PackageSelection::rebuild($data['private'], array_values($data['extras'])),
            is_string($name) ? $name : null,
            is_string($email) ? $email : null,
        );
    }
}
