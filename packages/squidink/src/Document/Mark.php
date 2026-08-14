<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document;

/**
 * An inline annotation applied to a Text node — bold, a link, a colour.
 *
 * Marks are a set on the text they apply to rather than wrapper nodes, which is
 * what lets "**bold with a [link](x) inside**" stay flat instead of nesting.
 * Borrowed from ProseMirror's document model; see Spec #80.
 *
 * Like nodes, every mark type must be declared in the Schema.
 */
abstract class Mark
{
    /** @var array<string, scalar|null> */
    protected array $attributes = [];

    /**
     * The name used in the schema and by renderers. Stable.
     */
    abstract public function type(): string;

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Two marks are the same if they are the same type with the same
     * attributes — used to avoid stacking duplicates on one text run.
     */
    public function equals(Mark $other): bool
    {
        return $this->type() === $other->type()
            && $this->attributes === $other->attributes;
    }
}
