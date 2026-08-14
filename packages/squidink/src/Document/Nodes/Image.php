<?php

declare(strict_types=1);

namespace Marque\SquidInk\Document\Nodes;

use Marque\SquidInk\Document\Node;

/**
 * An image reference.
 *
 * SquidInk deliberately does NOT resolve the reference. It holds whatever the
 * author wrote — usually a URL — and an image resolver decides what that means
 * at render time.
 *
 * Installing marque/stow registers a resolver that fetches the image once and
 * stores it locally, which fixes both link rot and leaking viewers' IPs to
 * third-party image hosts. Without a resolver the reference renders as-is.
 */
final class Image extends Node
{
    public function __construct(
        private string $src,
        private ?string $alt = null,
        private ?string $title = null,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'image';
    }

    public function allowsChildren(): bool
    {
        return false;
    }

    public function src(): string
    {
        return $this->src;
    }

    public function setSrc(string $src): static
    {
        $this->src = $src;

        return $this;
    }

    public function alt(): ?string
    {
        return $this->alt;
    }

    public function title(): ?string
    {
        return $this->title;
    }
}
