<?php

declare(strict_types=1);

namespace Marque\SquidInk\Contracts;

use Marque\SquidInk\Document\Nodes\Document;
use Marque\SquidInk\Document\Schema;

/**
 * Turns source text in one input syntax into a document.
 *
 * Parsers are peers. Markdown has no privileged position — a parser maps its
 * own syntax onto SquidInk nodes directly, never by converting to another
 * syntax first. That is what makes the suite genuinely input-agnostic rather
 * than "Markdown with extra steps".
 *
 * A parser must never throw on malformed input. Whatever it cannot understand
 * becomes literal text.
 */
interface Parser
{
    /**
     * The name this parser is registered and stored under. Records keep the
     * name of the parser that wrote them, so this string ends up in the
     * database and must stay stable.
     */
    public function name(): string;

    /**
     * Parse source text into a document, constrained by the schema.
     *
     * Implementations should build permissively and then run the result through
     * Schema::filter(), rather than checking the schema at every step.
     */
    public function parse(string $source, Schema $schema): Document;
}
