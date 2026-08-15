<?php

declare(strict_types=1);

namespace Marque\SquidInk\Editor;

/**
 * What a toolbar button inserts into the textarea.
 *
 * Deliberately dumb: a prefix, a suffix, and what to use when nothing is
 * selected. That covers every wrapping construct in both shipped syntaxes
 * (`**x**`, `[b]x[/b]`, `> x`, `[quote]x[/quote]`) without the editor needing to
 * understand any of them.
 *
 * The editor applies this to a text selection. It does not parse, validate, or
 * know which syntax it is writing — that knowledge stays in the parser that
 * produced the Insertion, which is what lets a parser we did not write ship a
 * working toolbar.
 */
final class Insertion
{
    /**
     * @param  string  $action  Intent name — "bold", "link", "quote".
     * @param  string  $label  What the button shows. Short: toolbars are narrow.
     * @param  string  $prefix  Inserted before the selection.
     * @param  string  $suffix  Inserted after the selection. Empty for line-level
     *                          constructs like a Markdown blockquote.
     * @param  string  $placeholder  Inserted between prefix and suffix when
     *                               nothing is selected, and then itself
     *                               selected so typing replaces it.
     * @param  bool  $block  Whether this belongs at the start of a line. The
     *                       editor moves to a new line first rather than
     *                       producing "text> quoted".
     */
    public function __construct(
        public string $action,
        public string $label,
        public string $prefix,
        public string $suffix = '',
        public string $placeholder = '',
        public bool $block = false,
    ) {}

    /**
     * Apply this insertion to a string, given a selected range.
     *
     * Lives here rather than in JavaScript so the behaviour is testable in PHP
     * and identical everywhere — the no-JS fallback and any future editor share
     * one implementation of what a button does.
     *
     * @param  int  $start  Byte offset of the selection start.
     * @param  int  $length  Length of the selection; 0 for a bare cursor.
     * @return array{text: string, start: int, length: int} The new text, and the
     *                                                      selection to leave behind.
     */
    public function applyTo(string $text, int $start, int $length = 0): array
    {
        $start = max(0, min($start, strlen($text)));
        $length = max(0, min($length, strlen($text) - $start));

        $before = substr($text, 0, $start);
        $selected = substr($text, $start, $length);
        $after = substr($text, $start + $length);

        // A block construct on the same line as existing text needs a break
        // first, or "some text" becomes "some text> quoted".
        if ($this->block && $before !== '' && ! str_ends_with($before, "\n")) {
            $before .= "\n";
        }

        $body = $selected === '' ? $this->placeholder : $selected;

        $text = $before.$this->prefix.$body.$this->suffix.$after;

        // Leave the body selected, so typing replaces the placeholder and a
        // wrapped selection stays wrapped.
        return [
            'text' => $text,
            'start' => strlen($before) + strlen($this->prefix),
            'length' => strlen($body),
        ];
    }

    /**
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'label' => $this->label,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'placeholder' => $this->placeholder,
            'block' => $this->block,
        ];
    }
}
