<?php

declare(strict_types=1);

namespace Marque\Disguise\Livewire\Torrent;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Marque\Disguise\Livewire\Component;
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
        // No viewer passed: the service resolves the current user, or a guest
        // when nobody is logged in — this route allows both.
        $torrents = $service->list(
            search: $this->search,
            perPage: config('disguise.per_page', 25),
            includeDead: $this->showDead,
        );

        return $this->disguiseView('disguise::torrent.index', [
            'torrents' => $torrents,
            'canShowDead' => (bool) config('trove.hide_dead_torrents', false),
        ]);
    }
}
