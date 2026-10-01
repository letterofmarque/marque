<?php

declare(strict_types=1);

// Spec #142 criterion 3. LoginCompletion is the one place an interactive login
// is finished, and so the one place allowed to sign a user in. Every path that
// used to do it itself — magic link, OAuth — was a path that forgot the
// two-factor challenge. A new path that calls Auth::login() directly would
// quietly reopen that, so this reads the source and says so.

/**
 * Does this line of code sign someone in without the seam? Any guard method
 * that authenticates, called on the facade or on anything at all — auth(),
 * auth('web'), Auth::guard(), app('auth'), a $guard variable — since the
 * review (Build #124 CP #764) found the first version missed most of them.
 */
function signsInDirectly(string $line): bool
{
    // `Auth::` (the facade, qualified or not), or `->` on anything except
    // `$this` — so a component's own login() action isn't mistaken for one.
    return (bool) preg_match(
        '/(?:\bAuth::|(?<!\$this)->\s*)(?:login|loginUsingId|attempt|attemptWhen|once|onceUsingId|setUser)\s*\(/',
        $line,
    );
}

it('recognises every way of signing someone in', function (string $line) {
    expect(signsInDirectly($line))->toBeTrue();
})->with([
    'Auth::login(' => ['Auth::login($user);'],
    'Auth::loginUsingId(' => ['Auth::loginUsingId($id);'],
    'Auth::attempt(' => ['Auth::attempt($credentials);'],
    'Auth::once(' => ['Auth::once($credentials);'],
    'Auth::onceUsingId(' => ['Auth::onceUsingId($id);'],
    'Auth::setUser(' => ['Auth::setUser($user);'],
    'auth()->login(' => ['auth()->login($user);'],
    'auth()->loginUsingId(' => ['auth()->loginUsingId($id);'],
    "auth('web')->login(" => ["auth('web')->login(\$user);"],
    'Auth::guard()->loginUsingId(' => ['Auth::guard()->loginUsingId($id);'],
    '$guard->login(' => ['$guard->login($user);'],
    "app('auth')->login(" => ["app('auth')->login(\$user);"],
    '->setUser(' => ['$this->guard->setUser($user);'],
    'attemptWhen(' => ['Auth::attemptWhen($credentials, $callbacks);'],
    'fully qualified' => ['\\Illuminate\\Support\\Facades\\Auth::login($user);'],
]);

it('leaves alone what does not sign anyone in', function (string $line) {
    expect(signsInDirectly($line))->toBeFalse();
})->with([
    'validate' => ["Auth::validate(['email' => \$e, 'password' => \$p]);"],
    'logout' => ['Auth::logout();'],
    'check' => ['auth()->check();'],
    "a component's own login() action" => ['public function login(): void'],
    'calling it' => ['$this->login();'],
    'a route name' => ["route('login');"],
]);

it('signs users in only through LoginCompletion', function () {
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
            if (signsInDirectly($line)) {
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
