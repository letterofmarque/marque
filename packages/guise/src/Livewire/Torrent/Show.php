<?php

declare(strict_types=1);

namespace Marque\Guise\Livewire\Torrent;

use Illuminate\Contracts\View\View;
use Marque\Guise\Livewire\Component;
use Marque\Trove\Models\Torrent;

class Show extends Component
{
    public Torrent $torrent;

    public function mount(Torrent $torrent): void
    {
        $this->torrent = $torrent->load('user');
    }

    public function render(): View
    {
        return $this->guiseView('guise::torrent.show')
            ->title($this->torrent->name);
    }
}
