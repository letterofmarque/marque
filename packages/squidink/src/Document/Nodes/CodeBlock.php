<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

/**
 * A preformatted block held verbatim.
 *
 * This node exists to be byte-for-byte faithful. NFO art and MediaInfo dumps
 * are the reason: they are ubiquitous on trackers, they are meaningful only if
 * every space survives, and a lossy conversion destroys them irreversibly.
 *
 * Content is stored as a raw string rather than child Text nodes precisely so
 * nothing can normalise whitespace or apply marks to it.
 */
final class CodeBlock extends Node
{
    public function __construct(
        private string $code,
        private ?string $language = null,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'code_block';
    }

    public function allowsChildren(): bool
    {
        return false;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function language(): ?string
    {
        return $this->language;
    }
}
