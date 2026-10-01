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
        public readonly ?string $nickname = null,
    ) {}

    /**
     * How to name this provider account to a person deciding whether it's
     * theirs: "@octocat (Octo Cat), id 583231".
     *
     * The provider's handle and id, because they tell one account from another.
     * Not the email: the label is shown to the holder of the address the
     * identity reported, so it is their own address by construction and says
     * nothing an attacker couldn't copy (Build #124 CP #776).
     *
     * Everything the provider supplied is reduced to plain text and capped. It
     * lands in a markdown mail, where a display name like "[Reset](https://…)"
     * otherwise renders as a live link.
     */
    public function label(): string
    {
        $handle = self::plain($this->nickname);
        $name = self::plain($this->name);

        $who = match (true) {
            $handle !== '' && $name !== '' && $name !== $handle => "@{$handle} ({$name})",
            $handle !== '' => "@{$handle}",
            default => $name,
        };

        $id = 'id '.self::plain($this->id);

        return $who === '' ? $id : "{$who}, {$id}";
    }

    private static function plain(?string $value): string
    {
        // Brackets, angle brackets, backticks, asterisks, backslashes and
        // control characters: what makes markdown or HTML out of plain text.
        // Underscores stay — they're common in handles and only ever emphasise.
        $value = preg_replace('/[\p{C}\[\]()<>`*\\\\]+/u', ' ', $value ?? '') ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strimwidth($value, 0, 60, '…');
    }
}
