<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

final class ComposerResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly string $output,
    ) {}
}
