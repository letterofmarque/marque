<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

final class BlockQuote extends Node
{
    public function type(): string
    {
        return 'block_quote';
    }
}
