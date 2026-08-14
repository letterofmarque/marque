<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

final class Paragraph extends Node
{
    public function type(): string
    {
        return 'paragraph';
    }
}
