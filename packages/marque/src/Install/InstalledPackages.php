<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use Closure;
use Composer\InstalledVersions;

/**
 * What composer has already installed in this app — read from composer's own
 * runtime record, so a re-run of marque:install starts from the truth rather
 * than from the operator's memory (#10803).
 */
final class InstalledPackages
{
    /** @param (Closure(string): bool)|null $isInstalled */
    public function __construct(private readonly ?Closure $isInstalled = null) {}

    /** @param list<string> $packages */
    public static function fake(array $packages): self
    {
        return new self(fn (string $package): bool => in_array($package, $packages, true));
    }

    public function has(string $package): bool
    {
        return $this->isInstalled !== null
            ? ($this->isInstalled)($package)
            : InstalledVersions::isInstalled($package);
    }
}
