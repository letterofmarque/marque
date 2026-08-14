<?php

declare(strict_types=1);

use Marque\SquidInk\Document\Marks\Bold;
use Marque\SquidInk\Document\Marks\Italic;
use Marque\SquidInk\Document\Nodes\CodeBlock;
use Marque\SquidInk\Document\Nodes\Document;
use Marque\SquidInk\Document\Nodes\Heading;
use Marque\SquidInk\Document\Nodes\HorizontalRule;
use Marque\SquidInk\Document\Nodes\Paragraph;
use Marque\SquidInk\Document\Nodes\Text;

describe('Node tree', function () {
    it('builds a tree and reports its children', function () {
        $doc = new Document(children: [
            new Paragraph(children: [new Text('hello')]),
        ]);

        expect($doc->type())->toBe('document')
            ->and($doc->hasChildren())->toBeTrue()
            ->and($doc->children())->toHaveCount(1)
            ->and($doc->children()[0])->toBeInstanceOf(Paragraph::class);
    });

    it('walks depth-first, itself first', function () {
        $doc = new Document(children: [
            new Paragraph(children: [new Text('one'), new Text('two')]),
        ]);

        $types = array_map(
            static fn ($node) => $node->type(),
            iterator_to_array($doc->walk(), false),
        );

        expect($types)->toBe(['document', 'paragraph', 'text', 'text']);
    });

    it('refuses children on leaf nodes', function () {
        expect(fn () => (new HorizontalRule)->append(new Text('nope')))
            ->toThrow(LogicException::class);
    });

    it('clamps heading levels to 1-6', function () {
        expect((new Heading(0))->level())->toBe(1)
            ->and((new Heading(9))->level())->toBe(6)
            ->and((new Heading(3))->level())->toBe(3);
    });
});

describe('Text and marks', function () {
    it('carries marks', function () {
        $text = new Text('bold', [new Bold]);

        expect($text->hasMark('bold'))->toBeTrue()
            ->and($text->hasMark('italic'))->toBeFalse();
    });

    it('does not stack duplicate marks', function () {
        $text = new Text('x', [new Bold, new Bold]);

        expect($text->marks())->toHaveCount(1);
    });

    it('keeps distinct marks together', function () {
        $text = new Text('x', [new Bold, new Italic]);

        expect($text->marks())->toHaveCount(2);
    });

    it('removes a mark by type', function () {
        $text = new Text('x', [new Bold, new Italic]);
        $text->removeMark('bold');

        expect($text->hasMark('bold'))->toBeFalse()
            ->and($text->hasMark('italic'))->toBeTrue();
    });
});

describe('CodeBlock', function () {
    it('holds content verbatim', function () {
        // NFO art is only meaningful if every byte survives.
        $nfo = "  ▄▄▄· ▄▄· ▄▄▄ .\n ▐█ ▀█ ▐█ ▌▪▀▄.▀·\n   trailing   ";

        $block = new CodeBlock($nfo);

        expect($block->code())->toBe($nfo);
    });

    it('optionally records a language', function () {
        expect((new CodeBlock('x', 'php'))->language())->toBe('php')
            ->and((new CodeBlock('x'))->language())->toBeNull();
    });
});
