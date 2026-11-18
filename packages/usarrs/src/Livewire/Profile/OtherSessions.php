<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Marque\Usarrs\Auth\SessionEpoch;
use Marque\Usarrs\Auth\StepUp;
use Marque\Usarrs\Livewire\Component;

/**
 * "Sign out every other browser" on the profile (#11154). It works under every
 * auth driver and never touches the password; see SessionEpoch.
 */
class OtherSessions extends Component
{
    /**
     * The page this sits on, for the return from confirming it's you. Locked,
     * so a client can't point that redirect elsewhere.
     */
    #[Locked]
    public string $returnTo = '';

    public bool $done = false;

    public function mount(): void
    {
        $this->returnTo = url()->current();
    }

    public function endOthers(): void
    {
        // A stolen session must not be able to sign the owner out, so it
        // takes the same confirmation as removing a connection.
        if (! StepUp::recent()) {
            session()->put('url.intended', $this->returnTo);
            $this->redirect(route('password.confirm'));

            return;
        }

        SessionEpoch::endOthers(auth()->user());

        $this->done = true;
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::profile.other-sessions');
    }
}
