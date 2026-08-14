<?php

declare(strict_types=1);

use Marque\SquidInk\Exceptions\UnknownFormat;
use Marque\SquidInk\SquidInk;

describe('SquidInk service', function () {
    it('resolves from the container with defaults registered', function () {
        $squidInk = app(SquidInk::class);

        expect($squidInk->parsers())->toContain('markdown')
            ->and($squidInk->renderers())->toContain('html')
            ->and($squidInk->renderers())->toContain('text');
    });

    it('is a singleton', function () {
        expect(app(SquidInk::class))->toBe(app(SquidInk::class));
    });

    it('converts source to html in one call', function () {
        expect(app(SquidInk::class)->convert('**bold**'))
            ->toBe('<p><strong>bold</strong></p>');
    });

    it('converts to plain text', function () {
        expect(app(SquidInk::class)->convert('**bold**', 'markdown', 'text'))
            ->toBe('bold');
    });

    it('uses the configured default parser', function () {
        expect(app(SquidInk::class)->convert('*i*'))
            ->toBe('<p><em>i</em></p>');
    });

    it('renders the same document to several formats', function () {
        $squidInk = app(SquidInk::class);
        $document = $squidInk->parse('# Title');

        expect($squidInk->render($document, 'html'))->toBe('<h1>Title</h1>')
            ->and($squidInk->render($document, 'text'))->toBe('Title');
    });

    it('names the available formats when asked for one it does not have', function () {
        $squidInk = app(SquidInk::class);

        expect(fn () => $squidInk->parse('x', 'textile'))
            ->toThrow(UnknownFormat::class, 'markdown');

        expect(fn () => $squidInk->render($squidInk->parse('x'), 'pdf'))
            ->toThrow(UnknownFormat::class);
    });

    it('reports which formats it knows', function () {
        $squidInk = app(SquidInk::class);

        expect($squidInk->hasParser('markdown'))->toBeTrue()
            ->and($squidInk->hasParser('bbcode'))->toBeTrue()
            ->and($squidInk->hasParser('textile'))->toBeFalse()
            ->and($squidInk->hasRenderer('html'))->toBeTrue();
    });

    it('parses either syntax through the same service', function () {
        $squidInk = app(SquidInk::class);

        expect($squidInk->convert('**bold**', 'markdown'))
            ->toBe($squidInk->convert('[b]bold[/b]', 'bbcode'));
    });
});
