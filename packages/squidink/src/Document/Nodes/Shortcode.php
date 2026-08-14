<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

/**
 * Platform-aware content: a spoiler, a MediaInfo block, a torrent status pill.
 *
 * A first-class node rather than a regex pass over rendered HTML, which is what
 * lets a shortcode render differently per output format and keeps it inside the
 * schema's guarantees.
 *
 * Consuming packages register their own by name. An unregistered shortcode
 * renders as literal text — never an error, never raw output — so content
 * written on a site with more shortcodes installed still reads sensibly here.
 */
final class Shortcode extends Node
{
    /**
     * @param  array<string, scalar|null>  $attributes
     * @param  list<Node>  $children
     */
    public function __construct(
        private string $name,
        array $attributes = [],
        array $children = [],
    ) {
        parent::__construct($attributes, $children);
    }

    public function type(): string
    {
        return 'shortcode';
    }

    public function name(): string
    {
        return $this->name;
    }
}
