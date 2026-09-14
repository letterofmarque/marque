<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

/**
 * The operator's answers, as a package list.
 *
 * The two tracker halves are mutually exclusive and that is the point rather
 * than a tidiness preference. hound registers `Route::get('announce')` through
 * loadRoutesFrom unconditionally, with no announce key and no authentication —
 * so a private tracker with hound merely present in vendor/ serves a keyless
 * announce endpoint beside its authenticated one, and anyone who finds it can
 * transfer without ever touching ratio accounting. Config cannot fix that
 * safely, because then the tracker's integrity sits one typo away from gone.
 *
 * So the wrong package is never installed at all, and PackageSelectionTest
 * asserts it rather than trusting this comment.
 */
final class PackageSelection
{
    private const PRIVATE_TRACKER = ['marque/bloodhound', 'marque/guise'];

    private const PUBLIC_TRACKER = ['marque/hound', 'marque/disguise'];

    /** @var list<string> */
    private array $extras = [];

    private function __construct(
        /** @var list<string> */
        private readonly array $tracker,
        private readonly bool $isPrivate,
    ) {}

    public static function private(): self
    {
        return new self(self::PRIVATE_TRACKER, true);
    }

    public static function public(): self
    {
        return new self(self::PUBLIC_TRACKER, false);
    }

    public function isPrivate(): bool
    {
        return $this->isPrivate;
    }

    public function withApi(): self
    {
        return $this->with('marque/cennad');
    }

    /**
     * parley requires squidink itself, so only parley is named. Listing a
     * transitive dependency is how a conceptual package list drifts from what
     * a consumer actually types (job #10539).
     */
    public function withForums(): self
    {
        return $this->with('marque/parley');
    }

    public function withTaxonomy(): self
    {
        return $this->with('marque/taxonomy');
    }

    public function withAdminPanel(): self
    {
        return $this->with('marque/skipper');
    }

    /**
     * Every tracker needs Redis: threepio's PeerService is Redis-backed peer
     * storage and threepio is in marque/marque's own require block, so this
     * holds for public and private alike (job #10700).
     */
    public function needsRedis(): bool
    {
        return true;
    }

    /**
     * Top-level packages only — composer resolves the rest. The core four
     * arrive with marque/marque itself and are deliberately absent here.
     *
     * @return list<string>
     */
    public function packages(): array
    {
        return array_values(array_unique([...$this->tracker, ...$this->extras]));
    }

    private function with(string $package): self
    {
        $clone = clone $this;

        if (! in_array($package, $clone->extras, true)) {
            $clone->extras[] = $package;
        }

        return $clone;
    }
}
