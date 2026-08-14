<?php

declare(strict_types=1);

namespace Marque\SquidInk;

use Marque\SquidInk\Contracts\Parser;
use Marque\SquidInk\Contracts\Renderer;
use Marque\SquidInk\Document\Node;
use Marque\SquidInk\Document\Nodes\Document;
use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Exceptions\UnknownFormat;

/**
 * The entry point: parse source text in some input syntax, render a document to
 * some output format.
 *
 *     $document = $squidInk->parse($body, 'markdown');
 *     $html     = $squidInk->render($document, 'html');
 *
 * Or in one step, which is what consumers usually want:
 *
 *     $html = $squidInk->convert($body, 'markdown', 'html');
 */
final class SquidInk
{
    /** @var array<string, Parser> */
    private array $parsers = [];

    /** @var array<string, Renderer> */
    private array $renderers = [];

    public function __construct(
        private Schema $schema,
        private string $defaultParser = 'markdown',
    ) {}

    public function registerParser(Parser $parser): self
    {
        $this->parsers[$parser->name()] = $parser;

        return $this;
    }

    public function registerRenderer(Renderer $renderer): self
    {
        $this->renderers[$renderer->name()] = $renderer;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function parsers(): array
    {
        return array_keys($this->parsers);
    }

    /**
     * @return list<string>
     */
    public function renderers(): array
    {
        return array_keys($this->renderers);
    }

    public function hasParser(string $name): bool
    {
        return isset($this->parsers[$name]);
    }

    public function hasRenderer(string $name): bool
    {
        return isset($this->renderers[$name]);
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    /**
     * @throws UnknownFormat
     */
    public function parse(string $source, ?string $parser = null, ?Schema $schema = null): Document
    {
        $name = $parser ?? $this->defaultParser;

        if (! isset($this->parsers[$name])) {
            throw UnknownFormat::parser($name, $this->parsers());
        }

        return $this->parsers[$name]->parse($source, $schema ?? $this->schema);
    }

    /**
     * @throws UnknownFormat
     */
    public function render(Node $document, string $renderer = 'html'): string
    {
        if (! isset($this->renderers[$renderer])) {
            throw UnknownFormat::renderer($renderer, $this->renderers());
        }

        return $this->renderers[$renderer]->render($document);
    }

    /**
     * Parse and render in one call.
     *
     * @throws UnknownFormat
     */
    public function convert(
        string $source,
        ?string $parser = null,
        string $renderer = 'html',
        ?Schema $schema = null,
    ): string {
        return $this->render($this->parse($source, $parser, $schema), $renderer);
    }
}
