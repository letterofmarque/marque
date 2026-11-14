<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Concerns;

use Marque\Usarrs\Auth\ChallengeGate;

/**
 * The app's human check on a Livewire form (Job #366). The widget writes its
 * answer into $challenge through the `[data-usarrs-challenge]` input.
 */
trait AsksChallenge
{
    public string $challenge = '';

    /**
     * Ask the check for $form. True when the submission may go ahead; false
     * when refused, with the error on `challenge`.
     *
     * The answer is cleared once asked and the widget told to reset: a
     * token like Turnstile's works once, so the next submission needs a
     * fresh one.
     */
    protected function passesChallenge(string $form): bool
    {
        if (! ChallengeGate::active($form)) {
            return true;
        }

        $refusal = ChallengeGate::refusal($form, $this->challenge, request());

        $this->challenge = '';
        $this->dispatch('usarrs-challenge-reset');

        if ($refusal === null) {
            return true;
        }

        $this->addError('challenge', $refusal);

        return false;
    }
}
