<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document;

/**
 * A structural piece of a document — a paragraph, a heading, a code block.
 *
 * Nodes form a tree. Inline formatting is not a node: it is a Mark applied to
 * a Text node, which keeps the tree shallow and makes "bold spanning two links"
 * representable without contortions.
 *
 * Every node type must be declared in the Schema before a parser can produce
 * it. That is the security boundary — see Schema.
 */
abstract class Node
{
    /** @var list<Node> */
    protected array $children = [];

    /** @var array<string, scalar|null> */
    protected array $attributes = [];

    /**
     * The name used in the schema and by renderers. Stable — renderers switch
     * on it, so changing one is a breaking change.
     */
    abstract public function type(): string;

    /**
     * Whether this node may contain children. Leaf nodes (Text, HorizontalRule,
     * Image) return false, and appending to one is a programming error.
     */
    public function allowsChildren(): bool
    {
        return true;
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     * @param  list<Node>  $children
     */
    public function __construct(array $attributes = [], array $children = [])
    {
        $this->attributes = $attributes;

        foreach ($children as $child) {
            $this->append($child);
        }
    }

    public function append(Node $child): static
    {
        if (! $this->allowsChildren()) {
            throw new \LogicException(
                sprintf('Node [%s] cannot contain children.', $this->type())
            );
        }

        $this->children[] = $child;

        return $this;
    }

    /**
     * @param  list<Node>  $children
     */
    public function appendAll(array $children): static
    {
        foreach ($children as $child) {
            $this->append($child);
        }

        return $this;
    }

    /**
     * @return list<Node>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * Replaces the child list wholesale. Used by Schema::filter() when unwrapping
     * disallowed nodes.
     *
     * @param  list<Node>  $children
     */
    public function replaceChildren(array $children): static
    {
        if (! $this->allowsChildren() && $children !== []) {
            throw new \LogicException(
                sprintf('Node [%s] cannot contain children.', $this->type())
            );
        }

        $this->children = array_values($children);

        return $this;
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
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

    public function setAttribute(string $key, string|int|float|bool|null $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Depth-first traversal, this node first.
     *
     * @return \Generator<Node>
     */
    public function walk(): \Generator
    {
        yield $this;

        foreach ($this->children as $child) {
            yield from $child->walk();
        }
    }
}
