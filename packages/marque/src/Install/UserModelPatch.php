<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use RuntimeException;

/**
 * Adds the Marque traits and contract to the app's User model.
 *
 * This fixes job #10699's headline fatal: a stock Laravel User model has none
 * of them, so `/torrents` — the product's main page — dies with
 * `Call to undefined method App\Models\User::isUploader()` on a by-the-book
 * install.
 *
 * Documenting the fix has already been tried and did not work. trove's README
 * carries it, and has named the wrong import for HasTrackerStats ever since
 * that trait moved to bloodhound in v2 (see docs/upgrade-guide-v2.md) — so the
 * one place that explains the fix hands you a fatal of its own.
 *
 * This is the most invasive thing the installer does. It edits a file the app
 * owns and did not ask us to touch, so the rules are strict: show the diff
 * first, back it up, never write without a yes, never write the same thing
 * twice, and never leave behind a file that will not parse.
 */
final class UserModelPatch
{
    private const ROLES_TRAIT = 'Marque\Trove\Concerns\HasRoles';

    /**
     * Lives in bloodhound, NOT trove — it moved there in v2. A public tracker
     * does not install bloodhound, so adding it there would fatal on a missing
     * class rather than fix anything.
     */
    private const STATS_TRAIT = 'Marque\Bloodhound\Concerns\HasTrackerStats';

    private const USER_CONTRACT = 'Marque\Trove\Contracts\UserInterface';

    public function __construct(private readonly string $path) {}

    /**
     * What is missing, without touching the file.
     *
     * @return array{traits: list<string>, interfaces: list<string>}
     */
    public function pending(bool $private): array
    {
        $contents = $this->contents();

        $traits = [self::ROLES_TRAIT];

        if ($private) {
            $traits[] = self::STATS_TRAIT;
        }

        return [
            'traits' => array_values(array_filter(
                $traits,
                fn (string $fqcn): bool => ! $this->alreadyUses($contents, $fqcn),
            )),
            'interfaces' => array_values(array_filter(
                [self::USER_CONTRACT],
                fn (string $fqcn): bool => ! $this->alreadyUses($contents, $fqcn),
            )),
        ];
    }

    /**
     * A unified-ish diff of what apply() would do.
     *
     * The operator is being asked to approve a change to their own application
     * code; a list of class names is not enough to judge that, so they see the
     * actual lines.
     */
    public function diff(bool $private): string
    {
        $before = explode("\n", $this->contents());
        $after = explode("\n", $this->patched($private));

        $lines = [];

        foreach ($this->changes($before, $after) as [$op, $line]) {
            $lines[] = $op === '+'
                ? '<fg=green>+</> '.$line
                : '<fg=red>-</> '.$line;
        }

        return implode("\n", $lines);
    }

    /**
     * Changed lines only, via a longest-common-subsequence walk.
     *
     * Comparing by line index instead drifts the moment anything is inserted:
     * every following line looks new, and the operator is asked to approve a
     * wall of green for what is really a five-line change.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<array{0: string, 1: string}>
     */
    private function changes(array $before, array $after): array
    {
        $rows = count($before);
        $cols = count($after);

        // lcs[i][j] = length of the longest common subsequence of the first
        // $i lines of $before and the first $j of $after.
        $lcs = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $cols - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $before[$i] === $after[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $changes = [];
        $i = 0;
        $j = 0;

        while ($i < $rows && $j < $cols) {
            if ($before[$i] === $after[$j]) {
                $i++;
                $j++;

                continue;
            }

            if ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $changes[] = ['-', $before[$i++]];
            } else {
                $changes[] = ['+', $after[$j++]];
            }
        }

        while ($i < $rows) {
            $changes[] = ['-', $before[$i++]];
        }

        while ($j < $cols) {
            $changes[] = ['+', $after[$j++]];
        }

        return $changes;
    }

    /**
     * @return array{traits: list<string>, interfaces: list<string>} what was added
     */
    public function apply(bool $private): array
    {
        $pending = $this->pending($private);

        if ($pending['traits'] === [] && $pending['interfaces'] === []) {
            return $pending;
        }

        $patched = $this->patched($private);

        // Never leave behind a User model that will not parse — a syntax error
        // here takes the entire application down, not just the tracker.
        $this->assertParses($patched);

        copy($this->path, $this->path.'.marque-backup');

        if (file_put_contents($this->path, $patched) === false) {
            throw new RuntimeException("Failed to write {$this->path}");
        }

        return $pending;
    }

    private function patched(bool $private): string
    {
        $contents = $this->contents();
        $pending = $this->pending($private);

        foreach ([...$pending['traits'], ...$pending['interfaces']] as $fqcn) {
            $contents = $this->addImport($contents, $fqcn);
        }

        foreach ($pending['traits'] as $fqcn) {
            $contents = $this->addTrait($contents, $this->shortName($fqcn));
        }

        foreach ($pending['interfaces'] as $fqcn) {
            $contents = $this->addInterface($contents, $this->shortName($fqcn));
        }

        return $contents;
    }

    /**
     * Appended after the last existing import, so the app's own grouping and
     * ordering survive. A commented-out import (Laravel ships
     * `// use ...MustVerifyEmail;`) is deliberately not matched — it is the
     * operator's note, not a live statement.
     */
    private function addImport(string $contents, string $fqcn): string
    {
        if (preg_match_all('/^use\s+[^;]+;$/m', $contents, $m, PREG_OFFSET_CAPTURE) === 0) {
            return $contents;
        }

        /** @var array{0: string, 1: int} $last */
        $last = end($m[0]);
        $at = $last[1] + strlen($last[0]);

        return substr($contents, 0, $at)."\nuse {$fqcn};".substr($contents, $at);
    }

    /**
     * Appended to the class's existing `use X, Y;` statement rather than added
     * as a second one, matching how Laravel writes it and how a person would.
     */
    private function addTrait(string $contents, string $trait): string
    {
        return preg_replace_callback(
            '/^(\s*)use\s+([A-Za-z_][A-Za-z0-9_]*(?:\s*,\s*[A-Za-z_][A-Za-z0-9_]*)*)\s*;/m',
            function (array $m) use ($trait): string {
                // Only the in-class trait statement is indented; a top-level
                // import is not, and must not be rewritten here.
                if ($m[1] === '') {
                    return $m[0];
                }

                return $m[1].'use '.$m[2].', '.$trait.';';
            },
            $contents,
            1,
        ) ?? $contents;
    }

    private function addInterface(string $contents, string $interface): string
    {
        // An existing implements clause is extended rather than replaced —
        // MustVerifyEmail is commonly already there.
        if (preg_match('/^(class\s+User\s+extends\s+\S+\s+implements\s+)([^\{\n]+)/m', $contents) === 1) {
            return preg_replace(
                '/^(class\s+User\s+extends\s+\S+\s+implements\s+)([^\{\n]+?)(\s*)$/m',
                '$1$2, '.$interface.'$3',
                $contents,
                1,
            ) ?? $contents;
        }

        return preg_replace(
            '/^(class\s+User\s+extends\s+\S+)/m',
            '$1 implements '.$interface,
            $contents,
            1,
        ) ?? $contents;
    }

    /**
     * Matches the import rather than the short name: a model that mentions
     * "HasRoles" in a comment has not imported it, and one that imported a
     * different HasRoles would be wrongly skipped by a bare name match.
     */
    private function alreadyUses(string $contents, string $fqcn): bool
    {
        return preg_match('/^use\s+'.preg_quote($fqcn, '/').'\s*;/m', $contents) === 1;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    private function assertParses(string $contents): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'marque-lint-');

        if ($temp === false) {
            throw new RuntimeException('Could not create a temporary file to validate the patched model');
        }

        try {
            file_put_contents($temp, $contents);

            $output = [];
            $status = 0;
            exec('php -l '.escapeshellarg($temp).' 2>&1', $output, $status);

            if ($status !== 0) {
                throw new RuntimeException(
                    "Patching the User model would have produced invalid PHP, so nothing was written:\n"
                    .implode("\n", $output)
                );
            }
        } finally {
            unlink($temp);
        }
    }

    private function contents(): string
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("No User model at {$this->path}");
        }

        return (string) file_get_contents($this->path);
    }
}
