<?php

declare(strict_types=1);

namespace Marque\SquidInk\Parsers\BBCode;

/**
 * A lexed piece of BBCode source: literal text, an opening tag, or a closer.
 *
 * Every token keeps the exact source text that produced it. That is what lets a
 * tag which turns out to be malformed — unclosed, unknown, or wrongly nested —
 * degrade back into precisely what the author typed, rather than an
 * approximation of it.
 */
final class Token
{
    public const TEXT = 'text';

    public const OPEN = 'open';

    public const CLOSE = 'close';

    public function __construct(
        public string $kind,
        public string $source,
        public string $name = '',
        public ?string $argument = null,
    ) {}

    public static function text(string $source): self
    {
        return new self(self::TEXT, $source);
    }

    public function isText(): bool
    {
        return $this->kind === self::TEXT;
    }

    public function isOpen(): bool
    {
        return $this->kind === self::OPEN;
    }

    public function isClose(): bool
    {
        return $this->kind === self::CLOSE;
    }
}
