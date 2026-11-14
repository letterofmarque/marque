<?php

declare(strict_types=1);

namespace Marque\Usarrs\Contracts;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Request;

/**
 * A human check (Turnstile, hCaptcha, anything) on the forms a script would
 * abuse: register, login (password and sign-in link) and password reset
 * (Job #366). usarrs ships none and requires no vendor. An app that wants one
 * binds an implementation:
 *
 *     $this->app->singleton(Challenge::class, TurnstileChallenge::class);
 *
 * With nothing bound, the forms are unchanged.
 *
 * usarrs refuses a missing or empty answer itself, before verify() is
 * called, so an implementation can't be bypassed by leaving the token out.
 */
interface Challenge
{
    /** The forms, as passed to every method. */
    public const REGISTER = 'register';

    public const LOGIN = 'login';

    public const PASSWORD_RESET = 'password-reset';

    /** Whether this form asks for an answer. */
    public function protects(string $form): bool;

    /**
     * The markup that shows the check inside the form. It must write its
     * answer into the form's `[data-usarrs-challenge]` input and dispatch an
     * `input` event on it. Once the check has been asked, usarrs clears the
     * answer and, on the Livewire forms, dispatches `usarrs-challenge-reset`
     * on window, so a single-use token can be replaced (Turnstile:
     * `turnstile.reset()`). The plain forgot-password form reloads instead.
     */
    public function widget(string $form): Htmlable|string;

    /** Whether $answer passes. Never called with an empty answer. */
    public function verify(string $answer, string $form, Request $request): bool;
}
