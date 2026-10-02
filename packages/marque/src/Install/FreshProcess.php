<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use Symfony\Component\Process\Process;

/**
 * Runs marque:install:finish in a new PHP process (Job #131).
 *
 * The process that ran composer require keeps the autoloader and providers it
 * booted with, so the packages it just installed — and the User model it just
 * patched — aren't loaded in it. A new `php artisan` boots with them.
 *
 * The finish step asks nothing, so its output is streamed back and no terminal
 * has to be handed over.
 */
class FreshProcess
{
    public function __construct(private readonly string $basePath) {}

    /**
     * @param  callable(string): void  $onOutput
     * @return int the finish command's exit code
     */
    public function finish(InstallAnswers $answers, callable $onOutput): int
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'marque:install:finish', $answers->encode(), '--no-interaction'],
            $this->basePath,
        );
        $process->setTimeout(null);
        $process->run(fn (string $type, string $buffer) => $onOutput($buffer));

        return $process->getExitCode() ?? 1;
    }
}
