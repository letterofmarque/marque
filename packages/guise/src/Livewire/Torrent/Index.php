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

    #[Url]
    public bool $showDead = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShowDead(): void
    {
        $this->resetPage();
    }

    public function render(TorrentService $service): View
    {
        // No viewer passed: the service defaults to the authenticated user, or
        // a guest when an app has dropped auth from guise.middleware (#10562).
        $torrents = $service->list(
            search: $this->search,
            includeDead: $this->showDead,
        );

        return $this->guiseView('guise::torrent.index', [
            'torrents' => $torrents,
            // The toggle is only meaningful when something is being hidden.
            'canShowDead' => (bool) config('trove.hide_dead_torrents', false),
        ]);
    }
}
