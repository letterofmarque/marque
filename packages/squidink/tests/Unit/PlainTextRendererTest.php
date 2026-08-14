<?php

declare(strict_types=1);

use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Parsers\MarkdownParser;
use Marque\SquidInk\Renderers\PlainTextRenderer;

function toText(string $source): string
{
    $document = (new MarkdownParser)->parse($source, Schema::permissive());

    return (new PlainTextRenderer)->render($document);
}

describe('PlainTextRenderer', function () {
    it('discards formatting rather than approximating it', function () {
        // No asterisks for bold — this output feeds search indexes and
        // notification excerpts, where fake markup is noise.
        expect(toText('**bold** and *italic*'))->toBe('bold and italic');
    });

    it('separates blocks with blank lines', function () {
        expect(toText("first\n\nsecond"))->toBe("first\n\nsecond");
    });

    it('renders headings as their text', function () {
        expect(toText('# Title'))->toBe('Title');
    });

    it('keeps code blocks verbatim', function () {
        $doc = "```\n  indented\n\tand tabbed\n```";

        expect(toText($doc))->toBe("  indented\n\tand tabbed");
    });

    it('renders link text without the URL', function () {
        expect(toText('[the text](https://example.com)'))->toBe('the text');
    });

    it('renders an image as its alt text', function () {
        expect(toText('![a screenshot](https://example.com/x.png)'))
            ->toBe('a screenshot');
    });

    it('renders an image with no alt text as nothing', function () {
        expect(toText('![](https://example.com/x.png)'))->toBe('');
    });

    it('renders list items on their own lines', function () {
        expect(toText("- one\n- two"))->toBe("one\ntwo");
    });

    it('never emits markup', function () {
        $text = toText('# H
**b** [l](https://x.example) `c`

> quote

- item');

        expect($text)->not->toContain('<')
            ->and($text)->not->toContain('>');
    });
});
