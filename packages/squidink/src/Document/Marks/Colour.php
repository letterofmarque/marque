<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Marks;

use Marque\SquidInk\Document\Mark;

/**
 * Coloured text. BBCode's [color] is widely used on older trackers, so the
 * document model carries it even though Markdown has no equivalent.
 *
 * Values are validated to hex codes or a fixed set of names. Arbitrary CSS is
 * not accepted — a colour value that reaches a style attribute unvalidated is a
 * CSS injection vector.
 */
final class Colour extends Mark
{
    public const NAMED = [
        'black', 'silver', 'gray', 'grey', 'white', 'maroon', 'red', 'purple',
        'fuchsia', 'green', 'lime', 'olive', 'yellow', 'navy', 'blue', 'teal',
        'aqua', 'orange', 'pink', 'brown',
    ];

    public function __construct(string $colour)
    {
        parent::__construct(['colour' => self::sanitise($colour)]);
    }

    public function type(): string
    {
        return 'colour';
    }

    public function colour(): string
    {
        return (string) $this->attribute('colour', '');
    }

    /**
     * Returns an empty string for anything unrecognised; renderers then emit
     * the text without colouring it.
     */
    public static function sanitise(string $colour): string
    {
        $value = strtolower(trim($colour));

        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value) === 1) {
            return $value;
        }

        return in_array($value, self::NAMED, true) ? $value : '';
    }
}
