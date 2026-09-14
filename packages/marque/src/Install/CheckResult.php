<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

final class CheckResult
{
    public function __construct(
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $detail = '',
    ) {}
}
