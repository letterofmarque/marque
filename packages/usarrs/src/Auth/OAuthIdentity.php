<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

/**
 * Who a provider says the returning user is.
 *
 * `id` is the provider's own, stable identifier and the only thing a login is
 * resolved by. `email` is a claim the provider makes, not proof of ownership of
 * an account here — it is used to *notice* a likely match, never to sign
 * anyone in (Spec #142).
 */
final class OAuthIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly ?string $email,
        public readonly ?string $name,
    ) {}
}
