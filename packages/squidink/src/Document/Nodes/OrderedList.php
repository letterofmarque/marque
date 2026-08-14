<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

final class OrderedList extends Node
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(int $start = 1, array $children = [])
    {
        parent::__construct(['start' => $start], $children);
    }

    public function type(): string
    {
        return 'ordered_list';
    }

    public function start(): int
    {
        return (int) $this->attribute('start', 1);
    }
}
