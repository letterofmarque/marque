<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

/**
 * Marks a string as a CBOR byte string rather than text.
 */
final class CborBytes
{
    public function __construct(public readonly string $bytes) {}
}
