<?php

declare(strict_types=1);

namespace Marque\Usarrs\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Marque\Usarrs\Livewire\Component;

/**
 * A member's seedboxes: register an address or range, see whether it is
 * approved, remove it.
 *
 * The tracker decides what a valid range is and what approval means; this
 * shows its answers, including its reason when it refuses one.
 */
class SeedboxManagement extends Component
{
    public string $network = '';

    public string $label = '';

    public function mount(): void
    {
        abort_unless($this->seedboxes() !== null, 404);
    }

    public function register(): void
    {
        $this->validate([
            'network' => ['required', 'string', 'max:43'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $seedbox = $this->seedboxes()->register(auth()->user(), $this->network, $this->label ?: null);
        } catch (InvalidArgumentException $refused) {
            $this->addError('network', $refused->getMessage());

            return;
        }

        $this->reset('network', 'label');

        session()->flash('status', $seedbox->isApproved()
            ? __('Seedbox :network added.', ['network' => $seedbox->network])
            : __('Seedbox :network added. It applies once an admin approves it.', ['network' => $seedbox->network]));
    }

    public function remove(int $seedboxId): void
    {
        try {
            $this->seedboxes()->remove(auth()->user(), $seedboxId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    public function render(): View
    {
        return $this->usarrsView('usarrs::profile.seedboxes', [
            'seedboxes' => $this->seedboxes()->forUser(auth()->user()),
        ])->title(__('Seedboxes'));
    }
}
