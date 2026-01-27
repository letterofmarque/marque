<?php

declare(strict_types=1);

namespace Marque\Guise\Livewire\Torrent;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Marque\Guise\Livewire\Component;
use Marque\Trove\Services\TorrentService;

#[Title('Torrents')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(TorrentService $service): View
    {
        $torrents = $service->list(search: $this->search);

        return $this->guiseView('guise::torrent.index', [
            'torrents' => $torrents,
        ]);
    }
}
