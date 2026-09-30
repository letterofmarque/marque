<?php

declare(strict_types=1);

namespace Marque\Usarrs\Contracts;

use Marque\Usarrs\Auth\OAuthIdentity;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The OAuth round trip, behind a seam usarrs owns.
 *
 * Socialite is an optional dependency — and one the suite cannot install at
 * all, since it pins guzzle <=7 against the suite's 8. Putting it behind this
 * interface is what lets the callback's security decisions be tested: a fake
 * can assert any identity, including one carrying somebody else's email.
 */
interface OAuthProvider
{
    public function redirect(string $provider): RedirectResponse;

    /**
     * The identity the provider asserts for the user returning from it.
     */
    public function user(string $provider): OAuthIdentity;
}
