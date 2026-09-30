<?php

declare(strict_types=1);

// Spec #142 criterion 3. LoginCompletion is the one place an interactive login
// is finished, and so the one place allowed to sign a user in. Every path that
// used to do it itself — magic link, OAuth — was a path that forgot the
// two-factor challenge. A new path that calls Auth::login() directly would
// quietly reopen that, so this reads the source and says so.

it('signs users in only through LoginCompletion', function () {
    $pattern = '/\b(Auth::login|Auth::loginUsingId|auth\(\)\s*->\s*login|Auth::guard\([^)]*\)\s*->\s*login)\s*\(/';
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        foreach (file($file->getPathname()) as $n => $line) {
            // Code only — the seam's own docblock names Auth::login() too.
            if (preg_match('/^\s*(\*|\/\/|\/\*)/', $line)) {
                continue;
            }
            if (preg_match($pattern, $line)) {
                $offenders[] = str_replace(realpath(__DIR__.'/../../').'/', '', realpath($file->getPathname())).':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe(['src/Auth/LoginCompletion.php:'.lineOfLogin()]);
});

function lineOfLogin(): int
{
    foreach (file(__DIR__.'/../../src/Auth/LoginCompletion.php') as $n => $line) {
        if (str_contains($line, 'Auth::login(') && ! preg_match('/^\s*\*/', $line)) {
            return $n + 1;
        }
    }

    return 0;
}
