<?php

declare(strict_types=1);

use Marque\SquidInk\Contracts\DescribesSyntax;
use Marque\SquidInk\Contracts\Parser;
use Marque\SquidInk\Document\Nodes\Document;
use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Editor\Insertion;
use Marque\SquidInk\Editor\Toolbar;
use Marque\SquidInk\Parsers\BBCodeParser;
use Marque\SquidInk\Parsers\MarkdownParser;
use Marque\SquidInk\Renderers\HtmlRenderer;

/**
 * A parser that does not describe its syntax — the default any third-party
 * parser gets for free, and the case the editor must still cope with.
 */
final class SilentParser implements Parser
{
    public function name(): string
    {
        return 'silent';
    }

    public function parse(string $source, Schema $schema): Document
    {
        return new Document;
    }
}

describe('Toolbar construction', function () {
    it('is built by asking the parser, not from a table of known formats', function () {
        expect(Toolbar::for(new MarkdownParser)->actions())
            ->toBe((new MarkdownParser)->actions());
    });

    it('is empty for a parser that does not describe its syntax', function () {
        $toolbar = Toolbar::for(new SilentParser);

        expect($toolbar->isEmpty())->toBeTrue()
            ->and($toolbar->actions())->toBe([]);
    });

    it('is empty when there is no parser at all', function () {
        expect(Toolbar::for(null)->isEmpty())->toBeTrue();
    });

    it('exposes each button to the browser as plain data', function () {
        $rows = Toolbar::for(new BBCodeParser)->toArray();

        expect($rows[0])->toHaveKeys(['action', 'label', 'prefix', 'suffix', 'placeholder', 'block']);
    });
});

describe('syntax differences surface as different toolbars', function () {
    it('offers underline, colour and size in BBCode but not Markdown', function (string $action) {
        expect(Toolbar::for(new BBCodeParser)->has($action))->toBeTrue()
            ->and(Toolbar::for(new MarkdownParser)->has($action))->toBeFalse();
    })->with(['underline', 'colour', 'size']);

    it('offers headings in Markdown but not BBCode', function () {
        expect(Toolbar::for(new MarkdownParser)->has('heading'))->toBeTrue()
            ->and(Toolbar::for(new BBCodeParser)->has('heading'))->toBeFalse();
    });

    it('spells a shared action differently per syntax', function () {
        $markdown = Toolbar::for(new MarkdownParser)->get('bold');
        $bbcode = Toolbar::for(new BBCodeParser)->get('bold');

        expect($markdown?->prefix)->toBe('**')
            ->and($bbcode?->prefix)->toBe('[b]');
    });

    it('declines an action it cannot express rather than inventing syntax', function () {
        expect((new MarkdownParser)->insertion('underline'))->toBeNull()
            ->and((new MarkdownParser)->insertion('nonsense'))->toBeNull();
    });
});

describe('Insertion applied to a selection', function () {
    it('wraps the selected text', function () {
        $result = (new Insertion('bold', 'B', '**', '**', 'text'))->applyTo('hello world', 0, 5);

        expect($result['text'])->toBe('**hello** world');
    });

    it('leaves the wrapped text selected', function () {
        $result = (new Insertion('bold', 'B', '**', '**', 'text'))->applyTo('hello world', 0, 5);

        expect(substr($result['text'], $result['start'], $result['length']))->toBe('hello');
    });

    it('inserts a placeholder when nothing is selected, and selects it', function () {
        $result = (new Insertion('bold', 'B', '**', '**', 'text'))->applyTo('', 0, 0);

        expect($result['text'])->toBe('**text**')
            ->and(substr($result['text'], $result['start'], $result['length']))->toBe('text');
    });

    it('works mid-string', function () {
        $result = (new Insertion('bold', 'B', '**', '**', 'text'))->applyTo('a b c', 2, 1);

        expect($result['text'])->toBe('a **b** c');
    });

    it('starts a new line for a block construct that would otherwise run on', function () {
        $result = (new Insertion('quote', 'Quote', '> ', '', 'quoted', block: true))
            ->applyTo('existing', 8, 0);

        expect($result['text'])->toBe("existing\n> quoted");
    });

    it('does not add a second newline when already at the start of a line', function () {
        $result = (new Insertion('quote', 'Quote', '> ', '', 'quoted', block: true))
            ->applyTo("existing\n", 9, 0);

        expect($result['text'])->toBe("existing\n> quoted");
    });

    it('clamps a selection beyond the end of the text', function () {
        $result = (new Insertion('bold', 'B', '**', '**', 'text'))->applyTo('hi', 99, 99);

        expect($result['text'])->toBe('hi**text**');
    });

    it('clamps a negative offset', function () {
        $result = (new Insertion('bold', 'B', '**', '**', 'text'))->applyTo('hi', -5, 2);

        expect($result['text'])->toBe('**hi**');
    });
});

describe('every button produces syntax its own parser understands', function () {
    /**
     * The contract that makes a parser-supplied toolbar safe: a button may not
     * insert something its parser will not parse. Without this, adding a button
     * is a chance to produce literal junk in a post.
     */
    it('round-trips every insertion back through the parser', function (string $parserClass) {
        /** @var Parser&DescribesSyntax $parser */
        $parser = new $parserClass;
        $renderer = new HtmlRenderer;

        foreach (Toolbar::for($parser)->insertions as $insertion) {
            $source = $insertion->applyTo('', 0, 0)['text'];
            $html = $renderer->render($parser->parse($source, Schema::permissive()));

            // Rendered output must differ from the escaped source: if they match,
            // the syntax was not recognised and came through as literal text.
            expect($html)->not->toBe('<p>'.htmlspecialchars($source, ENT_QUOTES).'</p>');

            // And no button may leave its own delimiters visible in the output.
            expect(strip_tags($html))->not->toContain($insertion->prefix);
        }
    })->with([MarkdownParser::class, BBCodeParser::class]);

    it('lists no action it cannot then produce', function (string $parserClass) {
        /** @var DescribesSyntax $parser */
        $parser = new $parserClass;

        foreach ($parser->actions() as $action) {
            expect($parser->insertion($action))->toBeInstanceOf(Insertion::class);
        }
    })->with([MarkdownParser::class, BBCodeParser::class]);
});
