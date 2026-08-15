<?php

declare(strict_types=1);

use Marque\SquidInk\SquidInk;

/**
 * The editor rendered as a Blade component.
 *
 * Neither Livewire nor marque/id is installed in this package's test
 * environment, which is the point: these tests are what prove squidink is
 * genuinely usable without either. A text pipeline that will not install without
 * a UI package or a frontend framework is the coupling the suite exists to
 * avoid, and the only way to keep that true is to test the bare case.
 */
describe('the editor without Livewire or id installed', function () {
    it('renders a usable textarea', function () {
        $html = Blade::render('<x-squidink::editor name="body" />');

        expect($html)->toContain('<textarea')
            ->and($html)->toContain('name="body"')
            ->and($html)->toContain('data-squidink-input');
    });

    it('does not fail when marque/id is absent', function () {
        // The id package is not installed here; a label must still render.
        $html = Blade::render('<x-squidink::editor name="body" label="Description" />');

        expect($html)->toContain('Description')
            ->and($html)->toContain('<label');
    });

    it('keeps an existing value in the textarea', function () {
        $html = Blade::render('<x-squidink::editor name="body" value="hello **world**" />');

        expect($html)->toContain('hello **world**');
    });

    it('escapes a value rather than letting it break out of the textarea', function () {
        $html = Blade::render('<x-squidink::editor name="body" value="</textarea><script>x</script>" />');

        expect($html)->not->toContain('<script>x</script>');
    });
});

describe('the toolbar reflects the active parser', function () {
    it('renders BBCode buttons for the bbcode parser', function () {
        $html = Blade::render('<x-squidink::editor name="body" parser="bbcode" />');

        expect($html)->toContain('data-prefix="[b]"')
            ->and($html)->toContain('data-squidink-action="colour"');
    });

    it('renders Markdown buttons for the markdown parser', function () {
        $html = Blade::render('<x-squidink::editor name="body" parser="markdown" />');

        expect($html)->toContain('data-prefix="**"')
            ->and($html)->toContain('data-squidink-action="heading"');
    });

    it('omits buttons the active parser cannot express', function () {
        $html = Blade::render('<x-squidink::editor name="body" parser="markdown" />');

        // Markdown has no underline, colour or size.
        expect($html)->not->toContain('data-squidink-action="underline"')
            ->and($html)->not->toContain('data-squidink-action="colour"');
    });

    it('falls back to the configured default parser', function () {
        $html = Blade::render('<x-squidink::editor name="body" />');

        expect($html)->toContain('data-parser="'.app(SquidInk::class)->defaultParser().'"');
    });

    it('ships the toolbar hidden so it never appears without its behaviour', function () {
        $html = Blade::render('<x-squidink::editor name="body" />');

        expect($html)->toContain('data-squidink-toolbar');

        // The toolbar div carries `hidden`; JS reveals it once wired up.
        $toolbar = substr($html, strpos($html, 'data-squidink-toolbar'));
        $toolbar = substr($toolbar, 0, strpos($toolbar, '>') + 1);

        expect($toolbar)->toContain('hidden');
    });
});

describe('optional dependencies stay optional', function () {
    it('boots and renders with Livewire absent', function () {
        // Livewire is not installed in this package's test environment. The
        // provider guards its registration, so booting must not have failed and
        // the plain component must still work.
        expect(class_exists(\Livewire\Livewire::class))->toBeFalse()
            ->and(Blade::render('<x-squidink::editor name="body" />'))->toContain('<textarea');
    });

    it('references no marque/id component in its own views', function (string $view) {
        $source = file_get_contents(__DIR__.'/../../resources/views/'.$view);

        // Blade resolves components at compile time, so a class_exists() guard
        // around <x-id::...> does not prevent an explosion where id is absent.
        // The only safe answer is not to reference them at all.
        expect($source)->not->toContain('<x-id::');
    })->with([
        'components/editor.blade.php',
        'livewire/editor.blade.php',
    ]);
});

describe('the no-JS path', function () {
    it('submits under the given field name without any scripting', function () {
        $html = Blade::render('<x-squidink::editor name="description" />');

        // A plain <textarea name="..."> inside a form is the whole fallback:
        // it posts, it saves, it renders. Everything else is enhancement.
        expect($html)->toContain('name="description"');
    });

    it('marks block-level insertions so the enhancer can place them', function () {
        $html = Blade::render('<x-squidink::editor name="body" parser="markdown" />');

        expect($html)->toContain('data-block');
    });
});
