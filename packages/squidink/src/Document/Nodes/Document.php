<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

/**
 * The root of a parsed document. Every parser returns one of these.
 */
final class Document extends Node
{
    public function type(): string
    {
        return 'document';
    }
}
