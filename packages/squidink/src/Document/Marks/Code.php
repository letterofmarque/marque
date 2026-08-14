<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Marks;

use Marque\SquidInk\Document\Mark;

/**
 * Inline code. The block-level equivalent is the CodeBlock node, which is a
 * node rather than a mark because it holds content verbatim.
 */
final class Code extends Mark
{
    public function type(): string
    {
        return 'code';
    }
}
