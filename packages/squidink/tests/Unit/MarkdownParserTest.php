<?php

declare(strict_types=1);

use Marque\SquidInk\Document\Nodes\CodeBlock;
use Marque\SquidInk\Document\Nodes\Heading;
use Marque\SquidInk\Document\Nodes\Image;
use Marque\SquidInk\Document\Nodes\OrderedList;
use Marque\SquidInk\Document\Nodes\Text;
use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Parsers\MarkdownParser;

function parseMarkdown(string $source, ?Schema $schema = null): \Marque\SquidInk\Document\Nodes\Document
{
    return (new MarkdownParser)->parse($source, $schema ?? Schema::permissive());
}

/**
 * @return list<string>
 */
function nodeTypes(\Marque\SquidInk\Document\Node $node): array
{
    return array_map(
        static fn ($n) => $n->type(),
        iterator_to_array($node->walk(), false),
    );
}

describe('MarkdownParser blocks', function () {
    it('parses a paragraph', function () {
        $doc = parseMarkdown('hello world');

        expect(nodeTypes($doc))->toBe(['document', 'paragraph', 'text']);
    });

    it('parses headings with their level', function () {
        $doc = parseMarkdown('### Third');

        $heading = $doc->children()[0];

        expect($heading)->toBeInstanceOf(Heading::class)
            ->and($heading->level())->toBe(3);
    });

    it('parses block quotes', function () {
        $doc = parseMarkdown('> quoted');

        expect($doc->children()[0]->type())->toBe('block_quote');
    });

    it('parses bullet lists', function () {
        $doc = parseMarkdown("- one\n- two");

        $list = $doc->children()[0];

        expect($list->type())->toBe('bullet_list')
            ->and($list->children())->toHaveCount(2)
            ->and($list->children()[0]->type())->toBe('list_item');
    });

    it('parses ordered lists and keeps their start', function () {
        $doc = parseMarkdown("3. three\n4. four");

        $list = $doc->children()[0];

        expect($list)->toBeInstanceOf(OrderedList::class)
            ->and($list->start())->toBe(3);
    });

    it('parses thematic breaks', function () {
        $doc = parseMarkdown('---');

        expect($doc->children()[0]->type())->toBe('horizontal_rule');
    });
});

describe('MarkdownParser line breaks', function () {
    it('treats a soft wrap as whitespace, not a break', function () {
        $doc = parseMarkdown("one\ntwo");

        // CommonMark: a single newline is where the source line happened to wrap.
        // Emitting a <br> here would make Markdown honour hard-wrapped source the
        // way BBCode does, which is a different language.
        expect(nodeTypes($doc))->not->toContain('hard_break');
    });

    it('honours an explicit hard break', function () {
        $doc = parseMarkdown("one  \ntwo");

        expect(nodeTypes($doc))->toContain('hard_break');
    });
});

describe('MarkdownParser code blocks', function () {
    it('keeps fenced code verbatim', function () {
        $nfo = "  ▄▄▄· ▄▄·\n ▐█ ▀█ ▐█ ▌▪   \n\ttabbed";

        $doc = parseMarkdown("```\n".$nfo."\n```");

        $block = $doc->children()[0];

        // Exactly what was between the fences: the newline before the closing
        // fence is syntax, not content. This is what lets the same block written
        // in BBCode be byte-identical — see FormatEquivalenceTest.
        expect($block)->toBeInstanceOf(CodeBlock::class)
            ->and($block->code())->toBe($nfo);
    });

    it('keeps a deliberate trailing blank line', function () {
        $doc = parseMarkdown("```\ncode\n\n```");

        // Only the closing fence's own newline is dropped, so a blank line the
        // author actually wanted survives.
        expect($doc->children()[0]->code())->toBe("code\n");
    });

    it('records the fence language', function () {
        $doc = parseMarkdown("```php\n<?php\n```");

        expect($doc->children()[0]->language())->toBe('php');
    });

    it('takes only the first word of a fence info string', function () {
        // "```php linenums=1" is common; the language is the first word.
        $doc = parseMarkdown("```php linenums=1\nx\n```");

        expect($doc->children()[0]->language())->toBe('php');
    });

    it('refuses a fence language containing markup characters', function () {
        // The language lands in a class attribute, so anything that could
        // escape it is dropped rather than escaped.
        $doc = parseMarkdown("```\"><script>\nx\n```");

        expect($doc->children()[0]->language())->toBeNull();
    });

    it('parses indented code', function () {
        $doc = parseMarkdown("    indented\n");

        expect($doc->children()[0])->toBeInstanceOf(CodeBlock::class);
    });
});

describe('MarkdownParser inline marks', function () {
    it('flattens emphasis into marked text', function () {
        $doc = parseMarkdown('**bold**');

        $text = $doc->children()[0]->children()[0];

        expect($text)->toBeInstanceOf(Text::class)
            ->and($text->text())->toBe('bold')
            ->and($text->hasMark('bold'))->toBeTrue();
    });

    it('nests marks without nesting nodes', function () {
        // The whole reason marks are not wrapper nodes.
        $doc = parseMarkdown('**bold *and italic***');

        $texts = array_values(array_filter(
            iterator_to_array($doc->walk(), false),
            static fn ($n) => $n instanceof Text,
        ));

        $both = array_values(array_filter(
            $texts,
            static fn (Text $t) => $t->hasMark('bold') && $t->hasMark('italic'),
        ));

        expect($both)->not->toBeEmpty();
    });

    it('parses strikethrough', function () {
        $doc = parseMarkdown('~~gone~~');

        expect($doc->children()[0]->children()[0]->hasMark('strike'))->toBeTrue();
    });

    it('parses inline code as a mark', function () {
        $doc = parseMarkdown('use `composer test` here');

        $code = array_values(array_filter(
            iterator_to_array($doc->walk(), false),
            static fn ($n) => $n instanceof Text && $n->hasMark('code'),
        ));

        expect($code[0]->text())->toBe('composer test');
    });

    it('parses links', function () {
        $doc = parseMarkdown('[text](https://example.com "Title")');

        $text = $doc->children()[0]->children()[0];
        $link = $text->mark('link');

        expect($link->href())->toBe('https://example.com')
            ->and($link->title())->toBe('Title');
    });

    it('parses images with alt text', function () {
        $doc = parseMarkdown('![a screenshot](https://example.com/x.png)');

        $image = $doc->children()[0]->children()[0];

        expect($image)->toBeInstanceOf(Image::class)
            ->and($image->src())->toBe('https://example.com/x.png')
            ->and($image->alt())->toBe('a screenshot');
    });
});

describe('MarkdownParser schema enforcement', function () {
    it('filters to the configured schema', function () {
        $doc = parseMarkdown('# Heading', Schema::minimal());

        // Heading is not in the minimal schema, so it unwraps to its text.
        expect(nodeTypes($doc))->not->toContain('heading')
            ->and(nodeTypes($doc))->toContain('text');
    });

    it('keeps the text of a filtered node', function () {
        $doc = parseMarkdown('# Important', Schema::minimal());

        $texts = array_filter(
            iterator_to_array($doc->walk(), false),
            static fn ($n) => $n instanceof Text,
        );

        expect(array_values($texts)[0]->text())->toBe('Important');
    });
});
