<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use RuntimeException;

/**
 * Sets one key in .env without disturbing anything else.
 *
 * .env is the operator's file. It carries comments, blank-line grouping and
 * hand-written ordering, and regenerating it to change one value would throw
 * all of that away — the same hostility Spec #115 rules out for app code. So
 * this rewrites exactly the matching line and leaves every other byte alone.
 */
final class EnvWriter
{
    public function __construct(private readonly string $path) {}

    public function set(string $key, string $value): void
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("No .env file at {$this->path}");
        }

        $contents = (string) file_get_contents($this->path);
        $line = $key.'='.$this->format($value);

        // Anchored to the start of a line, so APP_NAME never matches
        // APP_NAME_SUFFIX and a commented-out `# APP_NAME=` is left as the
        // operator's note rather than treated as the live setting.
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        $updated = preg_match($pattern, $contents) === 1
            ? preg_replace($pattern, $this->escapeReplacement($line), $contents, 1)
            : rtrim($contents, "\n")."\n".$line."\n";

        if (! is_string($updated)) {
            throw new RuntimeException("Failed to update {$key} in {$this->path}");
        }

        if (file_put_contents($this->path, $updated) === false) {
            throw new RuntimeException("Failed to write {$this->path}");
        }
    }

    /**
     * Unquoted, dotenv stops at the first space — "Ten Yard Tracker" would
     * silently become "Ten". Quote when the value is not a bare word, and
     * escape what would otherwise close the string early.
     */
    private function format(string $value): string
    {
        if ($value === '' || preg_match('/[\s"\'#=$]/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    /**
     * preg_replace reads $1, \1 and \\ in the replacement. A site name is
     * operator input and may contain any of them.
     */
    private function escapeReplacement(string $replacement): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement);
    }
}
