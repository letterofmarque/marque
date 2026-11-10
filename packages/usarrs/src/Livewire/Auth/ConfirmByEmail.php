<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Marque\Usarrs\Auth\EmailStepUp;
use Marque\Usarrs\Livewire\Component;

/**
 * The page the emailed "confirm it's you" link opens (Job #355). Opening it
 * does nothing but name the browser that asked; approving is a deliberate
 * action here, so a mail scanner following the link approves nothing. Works on
 * any device, signed in or not: the session that asked picks the approval up
 * (Auth\EmailStepUp).
 */
#[Title('Confirm it\'s you')]
class ConfirmByEmail extends Component
{
    #[Locked]
    public string $token = '';

    public bool $approved = false;

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function approve(): void
    {
        if (! EmailStepUp::approve($this->token)) {
            $this->addError('token', __('This link has already been used or has expired.'));

            return;
        }

        $this->approved = true;
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::auth.confirm-by-email', [
            'pending' => EmailStepUp::pending($this->token),
        ]);
    }
}
