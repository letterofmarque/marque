<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use RuntimeException;

/**
 * Tells Tailwind where the packages' templates are.
 *
 * Tailwind 4 generates only the classes it finds by scanning source files. A
 * fresh Laravel app declares `@source` for its own views and Laravel's
 * pagination views, and nothing under vendor/ — so every class written in
 * deck, guise and usarrs templates is never emitted, and the app renders with
 * the browser's defaults.
 *
 * That is the whole of the "dark mode is broken" report. deck's input
 * component correctly declares `dark:text-zinc-100` on `dark:bg-zinc-800`; the
 * stylesheet simply contained no such rule, so the text fell back to near-black
 * on a dark background. Measured in a clean-room install: 0 occurrences of
 * `placeholder:text-zinc-400`, 1 of `dark`, in 62KB of built CSS.
 *
 * The components are not the bug and must not be "fixed".
 */
final class StylesheetWiring
{
    /**
     * @param  string  $path  The app's resources/css/app.css
     * @param  string|null  $vendorPath  Where installed packages live. Injected
     *                                   so the check for "does this package
     *                                   ship views" is testable without a real
     *                                   composer install.
     */
    public function __construct(
        private readonly string $path,
        private readonly ?string $vendorPath = null,
    ) {}

    /**
     * The @source lines that are missing, without touching anything.
     *
     * Separate from wire() so the command can show the operator exactly what
     * it proposes to add and let them decline — nothing about this file is
     * written silently.
     *
     * @param  list<string>  $packages
     * @return list<string>
     */
    public function pending(array $packages): array
    {
        $contents = $this->contents();

        $lines = [];

        foreach ($packages as $package) {
            if (! $this->shipsViews($package)) {
                continue;
            }

            $line = $this->sourceLine($package);

            if (! str_contains($contents, $this->relativePath($package))) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $packages
     * @return list<string> the lines actually added
     */
    public function wire(array $packages): array
    {
        $lines = $this->pending($packages);

        if ($lines === []) {
            return [];
        }

        $contents = $this->contents();
        $block = implode("\n", $lines);

        // Grouped with the @source lines already there, rather than appended
        // at the end of the file: app.css is the operator's, it is commonly
        // customised, and a file that still reads as something a person wrote
        // is easier to maintain than one with machine leftovers at the bottom.
        $updated = $this->insertAfterLastSource($contents, $block)
            ?? $this->insertAfterImport($contents, $block)
            ?? rtrim($contents, "\n")."\n\n".$block."\n";

        if (file_put_contents($this->path, $updated) === false) {
            throw new RuntimeException("Failed to write {$this->path}");
        }

        return $lines;
    }

    private function insertAfterLastSource(string $contents, string $block): ?string
    {
        if (preg_match_all('/^@source\s+[^;]+;$/m', $contents, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        /** @var array{0: string, 1: int} $last */
        $last = end($matches[0]);
        $insertAt = $last[1] + strlen($last[0]);

        return substr($contents, 0, $insertAt)."\n".$block.substr($contents, $insertAt);
    }

    /**
     * A customised app.css may have dropped every @source line, but it will
     * still import tailwind — that is what makes it a Tailwind stylesheet.
     */
    private function insertAfterImport(string $contents, string $block): ?string
    {
        if (preg_match('/^@import\s+[^;]+;$/m', $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $insertAt = $m[0][1] + strlen($m[0][0]);

        return substr($contents, 0, $insertAt)."\n\n".$block.substr($contents, $insertAt);
    }

    /**
     * A package with no blade files gets no line. Pointing Tailwind at a
     * directory that does not exist is noise in the operator's file, and
     * bloodhound (routes, services, models, zero views) is the common case.
     */
    private function shipsViews(string $package): bool
    {
        $vendor = $this->vendorPath ?? base_path('vendor');

        $views = $vendor.'/'.$package.'/resources/views';

        if (! is_dir($views)) {
            return false;
        }

        return glob($views.'/{,*/,*/*/,*/*/*/}*.blade.php', GLOB_BRACE) !== [];
    }

    private function sourceLine(string $package): string
    {
        return "@source '".$this->relativePath($package)."';";
    }

    /**
     * Relative, matching the shape Laravel's own lines use — app.css lives at
     * resources/css/, so vendor/ is two levels up.
     */
    private function relativePath(string $package): string
    {
        return '../../vendor/'.$package.'/resources/views';
    }

    private function contents(): string
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("No stylesheet at {$this->path}");
        }

        return (string) file_get_contents($this->path);
    }
}
