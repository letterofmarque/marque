<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

final class HorizontalRule extends Node
{
    public function type(): string
    {
        return 'horizontal_rule';
    }

    public function allowsChildren(): bool
    {
        return false;
    }
}
