<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

final class HardBreak extends Node
{
    public function type(): string
    {
        return 'hard_break';
    }

    public function allowsChildren(): bool
    {
        return false;
    }
}
