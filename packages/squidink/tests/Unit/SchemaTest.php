<?php

declare(strict_types=1);

use Marque\SquidInk\Document\Marks\Bold;
use Marque\SquidInk\Document\Marks\Colour;
use Marque\SquidInk\Document\Marks\Link;
use Marque\SquidInk\Document\Nodes\Document;
use Marque\SquidInk\Document\Nodes\Heading;
use Marque\SquidInk\Document\Nodes\Image;
use Marque\SquidInk\Document\Nodes\Paragraph;
use Marque\SquidInk\Document\Nodes\Text;
use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Exceptions\SchemaViolation;

describe('Schema permissions', function () {
    it('permits everything by default', function () {
        $schema = Schema::permissive();

        expect($schema->allowsNode('image'))->toBeTrue()
            ->and($schema->allowsMark('colour'))->toBeTrue();
    });

    it('restricts to the declared set', function () {
        $schema = Schema::minimal();

        expect($schema->allowsNode('paragraph'))->toBeTrue()
            ->and($schema->allowsNode('image'))->toBeFalse()
            ->and($schema->allowsMark('link'))->toBeTrue()
            ->and($schema->allowsMark('colour'))->toBeFalse();
    });

    it('always includes the nodes a document cannot exist without', function () {
        // Ask for only headings; document/paragraph/text come along regardless.
        $schema = new Schema(['heading']);

        expect($schema->allowsNode('document'))->toBeTrue()
            ->and($schema->allowsNode('paragraph'))->toBeTrue()
            ->and($schema->allowsNode('text'))->toBeTrue();
    });
});

describe('Schema validation', function () {
    it('accepts a document within the schema', function () {
        $doc = new Document(children: [
            new Paragraph(children: [new Text('fine')]),
        ]);

        expect(Schema::minimal()->permits($doc))->toBeTrue();
    });

    it('rejects a disallowed node', function () {
        $doc = new Document(children: [new Image('x.png')]);

        expect(fn () => Schema::minimal()->validate($doc))
            ->toThrow(SchemaViolation::class);
    });

    it('rejects a disallowed mark', function () {
        $doc = new Document(children: [
            new Paragraph(children: [new Text('x', [new Colour('red')])]),
        ]);

        expect(fn () => Schema::minimal()->validate($doc))
            ->toThrow(SchemaViolation::class);
    });
});

describe('Schema filtering', function () {
    it('drops disallowed marks but keeps the text', function () {
        $text = new Text('important', [new Bold, new Colour('red')]);
        $doc = new Document(children: [new Paragraph(children: [$text])]);

        Schema::minimal()->filter($doc);

        expect($text->text())->toBe('important')
            ->and($text->hasMark('bold'))->toBeTrue()
            ->and($text->hasMark('colour'))->toBeFalse();
    });

    it('unwraps a disallowed node, keeping its children', function () {
        // Narrowing a schema should cost formatting, never content.
        $doc = new Document(children: [
            new Heading(2, [new Text('a heading')]),
        ]);

        Schema::minimal()->filter($doc);

        expect($doc->children())->toHaveCount(1)
            ->and($doc->children()[0])->toBeInstanceOf(Text::class)
            ->and($doc->children()[0]->text())->toBe('a heading');
    });

    it('removes a disallowed leaf node entirely', function () {
        // An image has no children, so there is nothing to keep.
        $doc = new Document(children: [
            new Paragraph(children: [new Text('before'), new Image('x.png')]),
        ]);

        Schema::minimal()->filter($doc);

        expect($doc->children()[0]->children())->toHaveCount(1);
    });

    it('leaves a permitted document untouched', function () {
        $doc = new Document(children: [
            new Paragraph(children: [new Text('x', [new Link('https://example.com')])]),
        ]);

        Schema::minimal()->filter($doc);

        expect($doc->children()[0]->children()[0]->hasMark('link'))->toBeTrue();
    });
});
