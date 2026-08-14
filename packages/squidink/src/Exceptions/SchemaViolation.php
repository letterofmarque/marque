<?php

declare(strict_types=1);

namespace Marque\SquidInk\Exceptions;

use RuntimeException;

final class SchemaViolation extends RuntimeException
{
    public static function node(string $type): self
    {
        return new self(sprintf(
            'Node type [%s] is not permitted by this schema.',
            $type
        ));
    }

    public static function mark(string $type): self
    {
        return new self(sprintf(
            'Mark type [%s] is not permitted by this schema.',
            $type
        ));
    }
}
