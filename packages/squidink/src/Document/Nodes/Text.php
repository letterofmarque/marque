<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Mark;
use Marque\SquidInk\Document\Node;

/**
 * A run of literal text, optionally carrying inline marks.
 *
 * The only node that holds content directly. Everything a reader actually reads
 * lives here; every other node exists to give this one structure.
 */
final class Text extends Node
{
    /** @var list<Mark> */
    private array $marks = [];

    /**
     * @param  list<Mark>  $marks
     */
    public function __construct(
        private string $text,
        array $marks = [],
    ) {
        parent::__construct();

        foreach ($marks as $mark) {
            $this->addMark($mark);
        }
    }

    public function type(): string
    {
        return 'text';
    }

    public function allowsChildren(): bool
    {
        return false;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function addMark(Mark $mark): static
    {
        foreach ($this->marks as $existing) {
            if ($existing->equals($mark)) {
                return $this;
            }
        }

        $this->marks[] = $mark;

        return $this;
    }

    /**
     * @return list<Mark>
     */
    public function marks(): array
    {
        return $this->marks;
    }

    public function removeMark(string $type): static
    {
        $this->marks = array_values(array_filter(
            $this->marks,
            static fn (Mark $mark): bool => $mark->type() !== $type,
        ));

        return $this;
    }

    public function hasMark(string $type): bool
    {
        foreach ($this->marks as $mark) {
            if ($mark->type() === $type) {
                return true;
            }
        }

        return false;
    }

    public function mark(string $type): ?Mark
    {
        foreach ($this->marks as $mark) {
            if ($mark->type() === $type) {
                return $mark;
            }
        }

        return null;
    }
}
