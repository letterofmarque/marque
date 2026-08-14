<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Marks;

use Marque\SquidInk\Document\Mark;

/**
 * A hyperlink.
 *
 * Scheme filtering happens here rather than in each parser, so every input
 * syntax gets the same protection and a new parser cannot forget it.
 * `javascript:`, `data:` and `vbscript:` URLs are the classic vector for
 * turning a link into script execution.
 */
final class Link extends Mark
{
    /**
     * Schemes a link may use. Anything else is refused — including scheme-less
     * URLs that resolve relative, which are allowed since they cannot escape
     * the host.
     */
    public const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'ftp', 'magnet'];

    public function __construct(string $href, ?string $title = null)
    {
        parent::__construct([
            'href' => self::sanitiseHref($href),
            'title' => $title,
        ]);
    }

    public function type(): string
    {
        return 'link';
    }

    public function href(): string
    {
        return (string) $this->attribute('href', '');
    }

    public function title(): ?string
    {
        $title = $this->attribute('title');

        return $title === null ? null : (string) $title;
    }

    /**
     * Returns an empty string for anything not on the allowlist. Renderers
     * treat an empty href as "render the text, drop the link", so a hostile
     * URL degrades to plain text rather than disappearing silently.
     */
    public static function sanitiseHref(string $href): string
    {
        $trimmed = trim($href);

        if ($trimmed === '') {
            return '';
        }

        // Strip control characters and whitespace that can be used to smuggle a
        // scheme past a naive check — "java\tscript:alert(1)" is a real payload.
        $normalised = preg_replace('/[\x00-\x20]+/', '', $trimmed) ?? '';

        if (! str_contains($normalised, ':')) {
            // Relative URL, fragment, or query — cannot carry a scheme.
            return $trimmed;
        }

        // A colon appearing after a slash or question mark is part of the path
        // or query, not a scheme separator: "/a/b:c" is relative.
        $colon = strpos($normalised, ':');
        $slash = strpos($normalised, '/');
        $question = strpos($normalised, '?');
        $hash = strpos($normalised, '#');

        foreach ([$slash, $question, $hash] as $marker) {
            if ($marker !== false && $marker < $colon) {
                return $trimmed;
            }
        }

        $scheme = strtolower(substr($normalised, 0, $colon));

        return in_array($scheme, self::ALLOWED_SCHEMES, true) ? $trimmed : '';
    }
}
