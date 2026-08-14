<?php

declare(strict_types=1);

namespace Marque\SquidInk\Contracts;

use Marque\SquidInk\Document\Node;

/**
 * Turns a document into output — HTML for the web, plain text for an API or a
 * search index, whatever else a consumer registers.
 *
 * Renderers only ever see nodes the schema permitted, so they do not sanitise.
 * They escape (because text content is still arbitrary) but they never have to
 * ask whether a node type is safe: an unsafe one could not have been built.
 */
interface Renderer
{
    /**
     * The name this renderer is registered under.
     */
    public function name(): string;

    public function render(Node $node): string;
}
