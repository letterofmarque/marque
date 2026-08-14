<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Marks;

use Marque\SquidInk\Document\Mark;

/**
 * No Markdown equivalent, but BBCode has [u] and users expect it.
 * A mark that only some parsers can produce is fine — that is the point of
 * having one document model behind several syntaxes.
 */
final class Underline extends Mark
{
    public function type(): string
    {
        return 'underline';
    }
}
