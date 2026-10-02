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

    private const EXTRAS = ['marque/cennad', 'marque/parley', 'marque/taxonomy', 'marque/skipper'];

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

    /**
     * The selection an existing install already embodies, or null on an app
     * with no tracker yet (#10803).
     *
     * Either half identifies the tracker: an app with only disguise left is
     * still a public install, and bloodhound would still be the wrong thing to
     * add. An app holding a half of each is refused rather than guessed at.
     * Extras already present are carried in, so the interview skips them and
     * the later wiring steps still know they are there.
     *
     * @throws MixedTrackerInstall
     */
    public static function fromInstalled(InstalledPackages $installed): ?self
    {
        $private = array_values(array_filter(self::PRIVATE_TRACKER, $installed->has(...)));
        $public = array_values(array_filter(self::PUBLIC_TRACKER, $installed->has(...)));

        if ($private !== [] && $public !== []) {
            throw new MixedTrackerInstall(sprintf(
                'This app has both tracker types installed (%s). That is a keyless announce endpoint beside an '
                .'authenticated one. Remove one with composer remove, then run marque:install again.',
                implode(', ', [...$private, ...$public]),
            ));
        }

        if ($private === [] && $public === []) {
            return null;
        }

        $selection = $private !== [] ? self::private() : self::public();

        foreach (self::EXTRAS as $extra) {
            if ($installed->has($extra)) {
                $selection = $selection->with($extra);
            }
        }

        return $selection;
    }

    /**
     * A selection from its parts, as InstallAnswers carries it. Only packages
     * the installer itself offers are accepted: the parts arrive on a command
     * line, and must not become a way to wire in anything else.
     *
     * @param  list<mixed>  $extras
     */
    public static function rebuild(bool $private, array $extras): self
    {
        $selection = $private ? self::private() : self::public();

        foreach ($extras as $extra) {
            if (! in_array($extra, self::EXTRAS, true)) {
                throw new \InvalidArgumentException('Not a package marque:install offers: '.json_encode($extra));
            }

            $selection = $selection->with($extra);
        }

        return $selection;
    }

    /** @return list<string> the optional packages chosen, tracker excluded */
    public function extras(): array
    {
        return $this->extras;
    }

    public function includes(string $package): bool
    {
        return in_array($package, $this->packages(), true);
    }

    /**
     * What composer still has to fetch: the selection minus what is installed.
     *
     * @return list<string>
     */
    public function toRequire(InstalledPackages $installed): array
    {
        return array_values(array_filter($this->packages(), fn (string $package): bool => ! $installed->has($package)));
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
