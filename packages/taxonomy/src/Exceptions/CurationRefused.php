<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Exceptions;

use RuntimeException;

/**
 * An edit to a catalogue's vocabulary that Curator won't make, and the field
 * it's about, so a form can put the message where the mistake is.
 */
class CurationRefused extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
