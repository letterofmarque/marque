<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use RuntimeException;

/**
 * Gives the app a front page.
 *
 * The finding that made Spec #115 worth writing: the suite registers thirty-odd
 * working routes and `/` is still Laravel's welcome page. Everything works and
 * nothing announces itself, so a stranger who follows the README sees an app
 * that looks completely unchanged.
 *
 * The splash is PUBLISHED into the app's own views rather than served from the
 * package. Every tracker replaces its front page immediately, and serving it
 * from here would make the common case require an override.
 */
final class HomePage
{
    public const SPLASH = 'splash';

    public const TORRENT_INDEX = 'torrents';

    public const PROFILE_STATS = 'profile-stats';

    public const LEAVE_ALONE = 'leave';

    /**
     * The marker that makes this idempotent. Written into routes/web.php so a
     * second run can tell its own route from the operator's.
     */
    private const MARKER = '// Marque home page';

    public function __construct(
        private readonly string $routesPath,
        private readonly string $viewsPath,
    ) {}

    /**
     * The choices, with what each one actually does.
     *
     * `dashboard` was in the original Checkpoint and is deliberately absent:
     * no route of that name exists anywhere in the suite, so offering it would
     * generate a route() call that fatals on first page load — the exact class
     * of bug this Build exists to remove (job #10709 covers building one).
     *
     * profile.stats rather than profile.show: show is name/email/role/bio,
     * while stats carries ratio, uploaded, downloaded and the announce key.
     * Someone pointing their front page at "my account" means the numbers.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::SPLASH => 'A splash page — your site name and an Enter link, yours to edit',
            self::TORRENT_INDEX => 'Straight to the torrent listing',
            self::PROFILE_STATS => 'Straight to the signed-in tracker stats — ratio, announce key',
            self::LEAVE_ALONE => 'Leave / alone — this app already has a home page',
        ];
    }

    public function pending(string $choice): bool
    {
        if ($choice === self::LEAVE_ALONE) {
            return false;
        }

        return ! str_contains($this->routes(), self::MARKER);
    }

    public function apply(string $choice): void
    {
        if ($choice === self::LEAVE_ALONE || ! $this->pending($choice)) {
            return;
        }

        if ($choice === self::SPLASH) {
            $this->publishSplash();
        }

        $contents = $this->replaceRoot($this->routes(), $this->routeFor($choice));

        $this->assertParses($contents);

        copy($this->routesPath, $this->routesPath.'.marque-backup');

        if (file_put_contents($this->routesPath, $contents) === false) {
            throw new RuntimeException("Failed to write {$this->routesPath}");
        }
    }

    private function routeFor(string $choice): string
    {
        $body = match ($choice) {
            self::SPLASH => "    return view('home');",
            self::TORRENT_INDEX => "    return redirect()->route('torrents.index');",
            self::PROFILE_STATS => "    return redirect()->route('profile.stats');",
            default => throw new RuntimeException("Unknown home page choice: {$choice}"),
        };

        return self::MARKER."\nRoute::get('/', function () {\n".$body."\n});";
    }

    /**
     * Replaces Laravel's welcome closure rather than adding a second route for
     * '/'. Two routes for the same path means the first wins silently and the
     * operator cannot tell which one they are looking at.
     */
    private function replaceRoot(string $contents, string $route): string
    {
        $pattern = "/Route::get\(\s*'\/'\s*,.*?\}\);/s";

        if (preg_match($pattern, $contents) === 1) {
            return preg_replace($pattern, $this->escapeReplacement($route), $contents, 1) ?? $contents;
        }

        // No root route at all — an app that deleted it. Append.
        return rtrim($contents, "\n")."\n\n".$route."\n";
    }

    /**
     * The splash belongs to the operator the moment it exists. A second run
     * must never overwrite one they have edited, which is the whole reason it
     * is published rather than rendered from the package.
     */
    private function publishSplash(): void
    {
        $path = $this->viewsPath.'/home.blade.php';

        if (is_file($path)) {
            return;
        }

        if (! is_dir($this->viewsPath)) {
            mkdir($this->viewsPath, 0o755, true);
        }

        file_put_contents($path, $this->splashTemplate());
    }

    /**
     * Modelled on twentyt's: a wordmark and an Enter link, nothing else. The
     * point is that it is a real page the operator can open and change, not a
     * finished design.
     */
    private function splashTemplate(): string
    {
        return <<<'BLADE'
{{--
    Your front page. This file is yours — edit it freely.

    marque:install published it once and will never overwrite it, so add your
    logo, change the colours, or replace the whole thing.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">
    <div class="flex min-h-screen flex-col items-center justify-center gap-10 px-6">
        <h1 class="text-center text-4xl font-semibold tracking-tight">
            {{ config('app.name') }}
        </h1>

        <a
            href="{{ route('torrents.index') }}"
            class="rounded-lg border border-zinc-300 px-8 py-3 text-lg transition
                   hover:bg-zinc-100 dark:border-zinc-600 dark:hover:bg-zinc-800"
        >
            Enter
        </a>
    </div>
</body>
</html>
BLADE;
    }

    /**
     * preg_replace reads $1, \1 and \\ in the replacement, and a generated
     * route body can contain them.
     */
    private function escapeReplacement(string $replacement): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement);
    }

    private function assertParses(string $contents): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'marque-routes-');

        if ($temp === false) {
            throw new RuntimeException('Could not create a temporary file to validate the routes file');
        }

        try {
            file_put_contents($temp, $contents);

            $output = [];
            $status = 0;
            exec('php -l '.escapeshellarg($temp).' 2>&1', $output, $status);

            if ($status !== 0) {
                throw new RuntimeException(
                    "Writing the home page route would have produced invalid PHP, so nothing was written:\n"
                    .implode("\n", $output)
                );
            }
        } finally {
            unlink($temp);
        }
    }

    private function routes(): string
    {
        if (! is_file($this->routesPath)) {
            throw new RuntimeException("No routes file at {$this->routesPath}");
        }

        return (string) file_get_contents($this->routesPath);
    }
}
