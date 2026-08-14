<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

final class BulletList extends Node
{
    public function type(): string
    {
        return 'bullet_list';
    }
}
