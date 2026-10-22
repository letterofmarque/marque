<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

/**
 * Turns bloodhound's hit-and-run rule on for a private tracker built from
 * nothing (Job #256).
 *
 * bloodhound ships the rule off, so an existing tracker is not changed under
 * it on upgrade. A tracker the installer builds from scratch has no members
 * yet to surprise, so it starts with the rule most private trackers run. A
 * re-run, a public tracker, or an operator who already set the key gets
 * nothing written.
 */
final class HitAndRunDefault
{
    public const KEY = 'BLOODHOUND_HIT_AND_RUN';

    public function __construct(private readonly string $envPath) {}

    /**
     * Whether it turned the rule on.
     */
    public function apply(PackageSelection $selection, bool $freshInstall): bool
    {
        if (! $freshInstall || ! $selection->isPrivate() || ! is_file($this->envPath)) {
            return false;
        }

        if (preg_match('/^'.self::KEY.'=/m', (string) file_get_contents($this->envPath)) === 1) {
            return false;
        }

        (new EnvWriter($this->envPath))->set(self::KEY, 'true');

        return true;
    }
}
