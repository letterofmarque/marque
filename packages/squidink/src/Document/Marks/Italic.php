<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Marks;

use Marque\SquidInk\Document\Mark;

final class Italic extends Mark
{
    public function type(): string
    {
        return 'italic';
    }
}
