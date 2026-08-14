<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

final class Heading extends Node
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(int $level = 1, array $children = [])
    {
        parent::__construct(['level' => max(1, min(6, $level))], $children);
    }

    public function type(): string
    {
        return 'heading';
    }

    public function level(): int
    {
        return (int) $this->attribute('level', 1);
    }
}
