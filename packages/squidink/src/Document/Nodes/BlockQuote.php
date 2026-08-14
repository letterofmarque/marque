<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

/**
 * Quoted content, optionally attributed.
 *
 * The attribution exists because of BBCode: `[quote=someone]` is how every
 * tracker forum has quoted a person for twenty years, and Markdown's `>` has no
 * way to say it. Dropping it would make quote-heavy imports anonymous, which
 * loses the thread of a conversation entirely.
 *
 * Markdown-parsed quotes simply leave it null — a node attribute that only one
 * dialect populates is the right shape for this, rather than two quote types.
 */
final class BlockQuote extends Node
{
    public function __construct(?string $author = null)
    {
        parent::__construct($author === null ? [] : ['author' => $author]);
    }

    public function type(): string
    {
        return 'block_quote';
    }

    /**
     * Who is being quoted, when the source syntax said so.
     */
    public function author(): ?string
    {
        $author = $this->attribute('author');

        return $author === null ? null : (string) $author;
    }
}
