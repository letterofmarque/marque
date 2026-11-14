<?php

declare(strict_types=1);

namespace Marque\Usarrs\Auth;

use Illuminate\Http\Request;
use Marque\Usarrs\Contracts\Challenge;

/**
 * Asks the app's Challenge, if it bound one (Job #366).
 *
 * The empty-answer refusal lives here, not in the app's implementation:
 * Kosh's Turnstile rule was a non-implicit validation rule, which Laravel
 * skips when the field is missing, so a script that left the token out
 * passed (Kosh Job #176). Here an absent answer is refused before verify()
 * is ever called.
 */
final class ChallengeGate
{
    /** Whether $form shows a check: a Challenge is bound and protects it. */
    public static function active(string $form): bool
    {
        return app()->bound(Challenge::class) && app(Challenge::class)->protects($form);
    }

    /** The refusal message, or null when the form may go ahead. */
    public static function refusal(string $form, mixed $answer, Request $request): ?string
    {
        if (! self::active($form)) {
            return null;
        }

        if (! is_string($answer) || trim($answer) === '') {
            return __('Please complete the check before continuing.');
        }

        return app(Challenge::class)->verify($answer, $form, $request)
            ? null
            : __('The check failed. Please try it again.');
    }
}
