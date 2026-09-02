<?php

declare(strict_types=1);

namespace Marque\SquidInk\Parsers\BBCode;

/**
 * One entry in the BBCode vocabulary.
 *
 * The tag table is a closed set, and that is the security property rather than a
 * convenience: a tag with no entry here is not a tag at all, so it is emitted as
 * the literal text the author typed. There is no path by which unrecognised
 * input becomes markup, which is what makes this parser safe in a way that the
 * regex-pile BBCode implementations on every other tracker are not.
 *
 * Whether a tag is closed is deliberately NOT declared here. The parser decides
 * by looking for a matching closer and degrading when it finds none, so a tag
 * that should have been paired but was not degrades by the same path as any
 * other malformed input — one mechanism rather than two disagreeing ones.
 */
final class Tag
{
    /**
     * How a tag's content is treated.
     */
    public const INLINE = 'inline';   // Emits a mark on its text: [b], [color]

    public const BLOCK = 'block';     // Emits a structural node: [quote], [list]

    public const VERBATIM = 'verbatim'; // Content is not parsed at all: [code]

    public const LEAF = 'leaf';       // Self-contained, content is its argument: [img]

    /**
     * @param  string  $kind  One of the constants above.
     */
    public function __construct(
        public string $name,
        public string $kind,
    ) {}

    public function isVerbatim(): bool
    {
        return $this->kind === self::VERBATIM;
    }
}
