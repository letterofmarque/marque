<?php

declare(strict_types=1);

namespace Marque\SquidInk\Exceptions;

use InvalidArgumentException;

final class UnknownFormat extends InvalidArgumentException
{
    /**
     * @param  list<string>  $available
     */
    public static function parser(string $name, array $available): self
    {
        return new self(sprintf(
            'No parser registered for [%s]. Available: %s.',
            $name,
            $available === [] ? 'none' : implode(', ', $available),
        ));
    }

    /**
     * @param  list<string>  $available
     */
    public static function renderer(string $name, array $available): self
    {
        return new self(sprintf(
            'No renderer registered for [%s]. Available: %s.',
            $name,
            $available === [] ? 'none' : implode(', ', $available),
        ));
    }
}
