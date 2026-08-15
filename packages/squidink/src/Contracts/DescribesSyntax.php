<?php

declare(strict_types=1);

namespace Marque\SquidInk\Contracts;

use Marque\SquidInk\Editor\Insertion;

/**
 * A parser that can tell an editor how to write its syntax.
 *
 * This exists so the editor toolbar does not contain a table of formats. If the
 * component knew that bold means `**` in Markdown and `[b]` in BBCode, then
 * adding a third input syntax would mean editing the editor, and any parser
 * shipped by someone else would get a toolbar that produced the wrong thing —
 * reintroducing a privileged format at the UI layer, which is exactly what the
 * package exists to avoid.
 *
 * Instead the toolbar asks the active parser. A parser that implements this gets
 * a working toolbar; one that does not still parses fine and simply renders a
 * bare textarea, which is why this is a separate interface rather than an
 * addition to Parser.
 *
 * Actions are named by intent — "bold", not "asterisks" — so the editor can
 * offer a consistent set of buttons across syntaxes that spell them differently.
 */
interface DescribesSyntax
{
    /**
     * How to write one action in this syntax, or null if it cannot express it.
     *
     * Returning null is normal rather than an error: Markdown has no underline
     * and no colour, BBCode has no headings. The editor omits buttons a parser
     * declines instead of offering ones that produce literal junk.
     */
    public function insertion(string $action): ?Insertion;

    /**
     * The actions this parser can express, in the order a toolbar should show
     * them. Every name here must return an Insertion from insertion().
     *
     * @return list<string>
     */
    public function actions(): array;
}
