<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use Symfony\Component\Process\Process;

/**
 * Shells out to composer for the packages the operator chose.
 *
 * Composer is run rather than its internals used: the installer runs inside an
 * app whose autoloader is already booted, and asking Composer to modify that
 * same install in-process is a known way to get a half-updated autoloader. A
 * subprocess gets a clean one.
 */
class ComposerRunner
{
    /** No timeout: a cold require of several packages genuinely can run long. */
    public function __construct(
        private readonly string $workingDirectory,
        private readonly ?int $timeout = null,
    ) {}

    /**
     * @param  list<string>  $packages
     * @param  null|callable(string): void  $onOutput
     */
    public function require(array $packages, ?callable $onOutput = null): ComposerResult
    {
        if ($packages === []) {
            return new ComposerResult(true, '');
        }

        $process = new Process(
            [$this->composerBinary(), 'require', ...$packages, '--no-interaction', '--with-all-dependencies'],
            $this->workingDirectory,
            // COMPOSER_MEMORY_LIMIT: resolving the suite is heavy and the
            // host's php.ini limit is not ours to assume.
            ['COMPOSER_MEMORY_LIMIT' => '-1'],
        );

        $process->setTimeout($this->timeout);

        // Streamed rather than buffered. A require of five packages takes long
        // enough that silence reads as a hang, and an operator who cannot see
        // progress kills it.
        $process->run(function (string $type, string $buffer) use ($onOutput): void {
            if ($onOutput !== null) {
                $onOutput($buffer);
            }
        });

        return new ComposerResult(
            $process->isSuccessful(),
            // Composer writes its real diagnostics to stderr, which is exactly
            // what the operator needs when resolution fails. Surfaced
            // verbatim rather than summarised.
            $process->getErrorOutput().$process->getOutput(),
        );
    }

    protected function composerBinary(): string
    {
        return 'composer';
    }
}
