<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\StylesheetWiring;
use Marque\Marque\Tests\TestCase;

/**
 * The real cause of the "dark mode is broken" reports.
 *
 * Tailwind 4 only emits classes it finds by scanning source files. A fresh
 * Laravel app's app.css declares @source for its own views and Laravel's
 * pagination views — and nothing under vendor/ — so every class declared in
 * deck, guise and usarrs templates is simply never generated.
 *
 * The components were always correct: deck's input declares
 * `dark:text-zinc-100` on `dark:bg-zinc-800`. The stylesheet just had no such
 * rule in it, so the text rendered with the browser default (near-black) on a
 * dark background. Measured in the clean-room app: `placeholder:text-zinc-400`
 * 0 occurrences, `dark` 1 occurrence, in 62KB of built CSS.
 */
final class StylesheetWiringTest extends TestCase
{
    private string $path;

    private string $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $id = uniqid();
        $this->path = sys_get_temp_dir().'/marque-css-'.$id.'.css';
        $this->vendor = sys_get_temp_dir().'/marque-vendor-'.$id;

        // A stand-in vendor/ tree. deck, guise and usarrs ship views;
        // bloodhound genuinely ships none, which is the case that must not
        // produce a @source line pointing at nothing.
        foreach (['deck', 'guise', 'usarrs'] as $package) {
            $dir = $this->vendor.'/marque/'.$package.'/resources/views';
            mkdir($dir, 0o777, true);
            file_put_contents($dir.'/example.blade.php', '<div></div>');
        }

        mkdir($this->vendor.'/marque/bloodhound/src', 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        $this->deleteTree($this->vendor);

        parent::tearDown();
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function wiring(): StylesheetWiring
    {
        return new StylesheetWiring($this->path, $this->vendor);
    }

    private function write(string $contents): void
    {
        file_put_contents($this->path, $contents);
    }

    private function read(): string
    {
        return (string) file_get_contents($this->path);
    }

    private function stockAppCss(): string
    {
        // Byte-for-byte the shape a fresh Laravel 13 app ships.
        return "@import 'tailwindcss';\n\n"
            ."@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';\n"
            ."@source '../../storage/framework/views/*.php';\n\n"
            ."@theme {\n    --font-sans: 'Instrument Sans', ui-sans-serif;\n}\n";
    }

    public function test_it_adds_a_source_line_for_an_installed_package(): void
    {
        $this->write($this->stockAppCss());

        $this->wiring()->wire(['marque/deck']);

        $this->assertStringContainsString("@source '../../vendor/marque/deck/resources/views'", $this->read());
    }

    public function test_it_adds_a_line_for_every_installed_package(): void
    {
        $this->write($this->stockAppCss());

        $this->wiring()->wire(['marque/deck', 'marque/guise', 'marque/usarrs']);

        foreach (['deck', 'guise', 'usarrs'] as $package) {
            $this->assertStringContainsString("vendor/marque/{$package}/resources/views", $this->read());
        }
    }

    public function test_it_is_idempotent(): void
    {
        $this->write($this->stockAppCss());

        $wiring = $this->wiring();
        $wiring->wire(['marque/deck']);
        $wiring->wire(['marque/deck']);

        $this->assertSame(
            1,
            substr_count($this->read(), 'vendor/marque/deck/resources/views'),
            'a second run must not duplicate the line',
        );
    }

    public function test_it_reports_what_it_would_add_without_writing(): void
    {
        $this->write($this->stockAppCss());
        $before = $this->read();

        $pending = $this->wiring()->pending(['marque/deck', 'marque/guise']);

        $this->assertCount(2, $pending);
        $this->assertSame($before, $this->read(), 'pending() must not touch the file');
    }

    public function test_pending_is_empty_once_everything_is_wired(): void
    {
        $this->write($this->stockAppCss());

        $wiring = $this->wiring();
        $wiring->wire(['marque/deck']);

        $this->assertSame([], $wiring->pending(['marque/deck']));
    }

    public function test_it_preserves_everything_already_in_the_file(): void
    {
        $this->write($this->stockAppCss());

        $this->wiring()->wire(['marque/deck']);

        $after = $this->read();

        // The operator's file. A customised app.css is common and none of it
        // may be disturbed to add a line.
        $this->assertStringContainsString("@import 'tailwindcss';", $after);
        $this->assertStringContainsString('Illuminate/Pagination', $after);
        $this->assertStringContainsString('storage/framework/views', $after);
        $this->assertStringContainsString('--font-sans', $after);
        $this->assertStringContainsString('@theme {', $after);
    }

    public function test_it_places_new_sources_with_the_existing_ones(): void
    {
        $this->write($this->stockAppCss());

        $this->wiring()->wire(['marque/deck']);

        $after = $this->read();

        // Grouped with the other @source lines rather than appended after the
        // @theme block, so the file still reads as something a person wrote.
        $this->assertLessThan(
            strpos($after, '@theme {'),
            strpos($after, 'vendor/marque/deck'),
            'the new @source belongs with the existing sources, above @theme',
        );
    }

    public function test_it_still_works_when_the_file_has_no_source_lines(): void
    {
        // Nothing guarantees a customised app.css kept them.
        $this->write("@import 'tailwindcss';\n");

        $this->wiring()->wire(['marque/deck']);

        $this->assertStringContainsString('vendor/marque/deck', $this->read());
        $this->assertStringContainsString("@import 'tailwindcss';", $this->read());
    }

    public function test_it_skips_packages_that_ship_no_views(): void
    {
        $this->write($this->stockAppCss());

        // bloodhound is a tracker: routes, services, models, zero blade files.
        // A @source line pointing at a directory that does not exist is noise
        // in the operator's file at best.
        $pending = $this->wiring()->pending(['marque/bloodhound']);

        $this->assertSame([], $pending);
    }
}
