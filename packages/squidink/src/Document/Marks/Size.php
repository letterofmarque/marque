<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Marks;

use Marque\SquidInk\Document\Mark;

/**
 * Sized text. BBCode's [size] has no Markdown equivalent, but twenty years of
 * scene posts use it, so the document model carries it rather than losing it on
 * import.
 *
 * Values are a closed set of steps, not arbitrary lengths. Accepting "72pt" or
 * "10em" would let a post blow out a page layout, and accepting arbitrary CSS
 * would be an injection vector — the same reasoning as Colour.
 *
 * A site that does not want users shouting narrows the schema; Schema::minimal()
 * already omits this mark.
 */
final class Size extends Mark
{
    /**
     * The classic BBCode 1-7 scale, mapped to relative sizes. Relative rather
     * than absolute so the host's own typography still governs — a size 7 in a
     * comment should not be larger than the page heading.
     */
    public const SCALE = [
        '1' => '0.7em',
        '2' => '0.85em',
        '3' => '1em',
        '4' => '1.2em',
        '5' => '1.5em',
        '6' => '2em',
        '7' => '2.5em',
    ];

    /**
     * Word forms some dialects use instead of numbers.
     */
    public const NAMED = [
        'tiny' => '1',
        'small' => '2',
        'normal' => '3',
        'medium' => '3',
        'large' => '5',
        'huge' => '7',
    ];

    public function __construct(string $size)
    {
        parent::__construct(['size' => self::sanitise($size)]);
    }

    public function type(): string
    {
        return 'size';
    }

    /**
     * The step, as a string from SCALE's keys. Empty when unrecognised.
     */
    public function size(): string
    {
        return (string) $this->attribute('size', '');
    }

    /**
     * The CSS length for this step, or an empty string when unrecognised.
     */
    public function length(): string
    {
        return self::SCALE[$this->size()] ?? '';
    }

    /**
     * Returns an empty string for anything unrecognised; renderers then emit the
     * text without sizing it.
     *
     * Out-of-range numbers clamp rather than fail — "[size=99]" is someone
     * shouting, and their text should still survive at the largest step.
     */
    public static function sanitise(string $size): string
    {
        $value = strtolower(trim($size));

        if ($value === '') {
            return '';
        }

        if (isset(self::NAMED[$value])) {
            return self::NAMED[$value];
        }

        // Some dialects write sizes in px. Map them onto the nearest step
        // instead of honouring the exact value.
        if (preg_match('/^(\d+)(?:px|pt)?$/', $value, $matches) === 1) {
            $number = (int) $matches[1];

            if ($number > 7) {
                // A px-style value: anything up to ~12px is small, and it grows
                // from there. Beyond the top step it clamps.
                $number = match (true) {
                    $number <= 10 => 1,
                    $number <= 13 => 2,
                    $number <= 16 => 3,
                    $number <= 20 => 4,
                    $number <= 26 => 5,
                    $number <= 36 => 6,
                    default => 7,
                };
            }

            return $number >= 1 ? (string) $number : '';
        }

        return '';
    }
}
