<?php

declare(strict_types=1);

namespace Marque\SquidInk\Editor;

use Marque\SquidInk\Contracts\DescribesSyntax;
use Marque\SquidInk\Contracts\Parser;

/**
 * The buttons an editor should show for one parser.
 *
 * Built by asking the parser, never by consulting a table of known formats. A
 * parser that does not describe its syntax yields an empty toolbar and the
 * editor renders a bare textarea — which is a working editor, just a plainer
 * one, and is what any parser gets by default.
 */
final class Toolbar
{
    /**
     * @param  list<Insertion>  $insertions
     */
    private function __construct(
        public array $insertions,
    ) {}

    public static function for(?Parser $parser): self
    {
        if (! $parser instanceof DescribesSyntax) {
            return new self([]);
        }

        $insertions = [];

        foreach ($parser->actions() as $action) {
            $insertion = $parser->insertion($action);

            // A parser listing an action it cannot produce is a bug in that
            // parser, not something to propagate into the UI.
            if ($insertion !== null) {
                $insertions[] = $insertion;
            }
        }

        return new self($insertions);
    }

    public function isEmpty(): bool
    {
        return $this->insertions === [];
    }

    public function has(string $action): bool
    {
        return $this->get($action) !== null;
    }

    public function get(string $action): ?Insertion
    {
        foreach ($this->insertions as $insertion) {
            if ($insertion->action === $action) {
                return $insertion;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map(static fn (Insertion $i): string => $i->action, $this->insertions);
    }

    /**
     * The shape handed to the browser, for a toolbar driven client-side.
     *
     * @return list<array<string, scalar>>
     */
    public function toArray(): array
    {
        return array_map(static fn (Insertion $i): array => $i->toArray(), $this->insertions);
    }
}
