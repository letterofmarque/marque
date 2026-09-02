<?php

declare(strict_types=1);

namespace Marque\Trove\Support;

use Marque\Trove\Contracts\UserInterface;

/**
 * Who a read is being performed as.
 *
 * This type exists to close a fail-open gap. Access filtering needs three
 * distinct states, and a plain `?UserInterface` can only express two:
 *
 *   - a specific user       -> filter to what that user may see
 *   - explicitly a guest    -> filter to unrestricted torrents only
 *   - not specified         -> fall back to the authenticated user
 *
 * Without the third, `list($perPage, $search)` — a call written before access
 * control existed, or one where someone simply forgot the argument — resolves
 * its default of `null` to "guest". That is safe by accident here, but the
 * same shape one refactor later ("null means unrestricted") is a silent leak
 * of every restricted torrent. Making "unspecified" a value of its own means
 * the default can be *defined* rather than inferred, and the definition is
 * "whoever is logged in", which is what a web request almost always wants.
 */
final class ViewerScope
{
    private function __construct(
        public readonly ?UserInterface $user,
    ) {}

    /**
     * Read as a specific user.
     */
    public static function user(UserInterface $user): self
    {
        return new self($user);
    }

    /**
     * Read as an unauthenticated visitor — unrestricted torrents only.
     *
     * Deliberately explicit: a caller has to say "this really is a guest"
     * rather than getting guest scope by omitting an argument.
     */
    public static function guest(): self
    {
        return new self(null);
    }

    /**
     * Read as whoever is currently authenticated, or as a guest if nobody is.
     *
     * The default when no viewer is passed.
     */
    public static function current(): self
    {
        $user = auth()->user();

        return new self($user instanceof UserInterface ? $user : null);
    }
}
