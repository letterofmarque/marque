<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use Marque\Usarrs\Auth\OAuthIdentity;
use Marque\Usarrs\Contracts\OAuthProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Stands in for Socialite, which cannot be installed in the suite (it pins
 * guzzle <=7 against the suite's 8 — see phpstan.neon). The callback's whole
 * job is deciding what to do with an identity a provider asserts, so the fake
 * asserts exactly the identity a test chooses — including one carrying
 * somebody else's email address, which is the attack.
 */
class FakeOAuthProvider implements OAuthProvider
{
    public ?OAuthIdentity $next = null;

    public function redirect(string $provider): RedirectResponse
    {
        return new RedirectResponse("https://oauth.example/{$provider}/authorize");
    }

    public function user(string $provider): OAuthIdentity
    {
        return $this->next ?? throw new \LogicException('FakeOAuthProvider: set $next before the callback');
    }

    public function asserts(string $provider, string $id, ?string $email, ?string $name = 'OAuth Person', ?string $nickname = null): self
    {
        $this->next = new OAuthIdentity($provider, $id, $email, $name, $nickname);

        return $this;
    }
}
