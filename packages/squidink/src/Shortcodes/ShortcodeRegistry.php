<?php

declare(strict_types=1);

namespace Marque\SquidInk\Shortcodes;

use Marque\SquidInk\Contracts\Shortcode;
use Marque\SquidInk\Document\Nodes\Shortcode as ShortcodeNode;

/**
 * Known shortcodes, by name.
 *
 * Parsers ask whether a name is registered before producing a Shortcode node,
 * so unregistered shortcodes stay literal text rather than becoming nodes that
 * nothing can render. Content written on a site with more shortcodes installed
 * therefore still reads sensibly here — it shows the source rather than
 * vanishing or erroring.
 */
final class ShortcodeRegistry
{
    /** @var array<string, Shortcode> */
    private array $shortcodes = [];

    public function register(Shortcode $shortcode): self
    {
        $this->shortcodes[strtolower($shortcode->name())] = $shortcode;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->shortcodes[strtolower($name)]);
    }

    public function get(string $name): ?Shortcode
    {
        return $this->shortcodes[strtolower($name)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->shortcodes);
    }

    public function isPaired(string $name): bool
    {
        return $this->get($name)?->isPaired() ?? false;
    }

    /**
     * Render a node in one format, or null if nothing handles it.
     *
     * @param  callable(): string  $renderChildren
     */
    public function render(ShortcodeNode $node, string $format, callable $renderChildren): ?string
    {
        return $this->get($node->name())?->render($node, $format, $renderChildren);
    }
}
