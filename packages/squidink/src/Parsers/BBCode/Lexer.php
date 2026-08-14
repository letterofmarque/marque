<?php

declare(strict_types=1);

namespace Marque\SquidInk\Parsers\BBCode;

/**
 * Turns BBCode source into a flat token run.
 *
 * Lexing is separate from tree-building because the two failure modes are
 * different: this stage decides what looks like a tag, and the parser decides
 * whether a tag that looks fine is actually usable where it appears. Keeping
 * them apart is what makes "degrades to literal text" testable in isolation.
 *
 * A verbatim tag ([code]) suspends lexing entirely until its matching closer, so
 * bracket syntax inside a code block is content rather than markup. That is
 * required for NFO art and MediaInfo dumps, which are full of brackets.
 */
final class Lexer
{
    /**
     * `[name]`, `[name=argument]` or `[/name]`. Names are letters and digits,
     * plus the bare `[*]` list-item marker — deliberately narrow, since anything
     * not matching is left as text.
     *
     * `[*]` is the one tag whose name is punctuation. It is in the pattern rather
     * than special-cased later so that an unclosed-item marker is a token like
     * any other and the tag table stays the single source of truth.
     *
     * The argument may be quoted, which is how a URL containing `]` survives.
     */
    private const TAG = '/\[(\/?)(\*|[a-z][a-z0-9]*)(?:=(?:"([^"]*)"|\'([^\']*)\'|([^\]]*)))?\]/i';

    /**
     * @param  array<string, Tag>  $tags
     */
    public function __construct(
        private array $tags,
    ) {}

    /**
     * @return list<Token>
     */
    public function tokenise(string $source): array
    {
        $tokens = [];
        $cursor = 0;
        $length = strlen($source);

        while ($cursor < $length) {
            $next = strpos($source, '[', $cursor);

            if ($next === false) {
                $tokens[] = Token::text(substr($source, $cursor));
                break;
            }

            if (preg_match(self::TAG, $source, $matches, PREG_OFFSET_CAPTURE, $cursor) !== 1) {
                // No further tag anywhere: the rest is text.
                $tokens[] = Token::text(substr($source, $cursor));
                break;
            }

            [$literal, $offset] = $matches[0];

            if ($offset > $cursor) {
                $tokens[] = Token::text(substr($source, $cursor, $offset - $cursor));
            }

            $name = strtolower($matches[2][0]);
            $closing = $matches[1][0] === '/';
            $cursor = $offset + strlen($literal);

            // Unknown tag: keep the source verbatim as text. This is the closed
            // vocabulary doing its job.
            if (! isset($this->tags[$name])) {
                $tokens[] = Token::text($literal);

                continue;
            }

            if ($closing) {
                $tokens[] = new Token(Token::CLOSE, $literal, $name);

                continue;
            }

            $tokens[] = new Token(
                Token::OPEN,
                $literal,
                $name,
                $this->argument($matches),
            );

            // Verbatim content is consumed here so no tag inside it is ever
            // lexed. Without a closer the tag is not verbatim after all — it
            // degrades like any other unclosed tag.
            if ($this->tags[$name]->isVerbatim()) {
                $end = $this->findCloser($source, $name, $cursor);

                if ($end === null) {
                    continue;
                }

                if ($end > $cursor) {
                    $tokens[] = Token::text(substr($source, $cursor, $end - $cursor));
                }

                $tokens[] = new Token(Token::CLOSE, substr($source, $end, strlen($name) + 3), $name);
                $cursor = $end + strlen($name) + 3;
            }
        }

        return $tokens;
    }

    /**
     * The offset of the matching closer for a verbatim tag, or null when it is
     * never closed.
     */
    private function findCloser(string $source, string $name, int $from): ?int
    {
        $position = stripos($source, '[/'.$name.']', $from);

        return $position === false ? null : $position;
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $matches
     */
    private function argument(array $matches): ?string
    {
        foreach ([3, 4, 5] as $group) {
            if (isset($matches[$group]) && $matches[$group][1] !== -1) {
                return $matches[$group][0];
            }
        }

        return null;
    }
}
