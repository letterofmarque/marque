<?php

declare(strict_types=1);

namespace Marque\Disguise\Livewire\Torrent;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Marque\Disguise\Livewire\Component;
use Marque\Trove\Models\Torrent;

class Show extends Component
{
    use AuthorizesRequests;

    public Torrent $torrent;

    public function mount(Torrent $torrent): void
    {
        $this->authorize('view', $torrent);

        $this->torrent = $torrent->load('user');
    }

    public function render(): View
    {
        return $this->disguiseView('disguise::torrent.show')
            ->title($this->torrent->name);
    }
}
