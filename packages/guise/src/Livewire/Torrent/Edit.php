<?php

declare(strict_types=1);

namespace Marque\Guise\Livewire\Torrent;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Marque\Guise\Livewire\Component;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Services\TorrentService;

class Edit extends Component
{
    use AuthorizesRequests;

    public Torrent $torrent;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:10000')]
    public string $description = '';

    public function mount(Torrent $torrent): void
    {
        $this->authorize('update', $torrent);

        $this->torrent = $torrent;
        $this->name = $torrent->name;
        $this->description = $torrent->description ?? '';
    }

    public function save(TorrentService $service): void
    {
        $this->authorize('update', $this->torrent);

        $this->validate();

        $service->update($this->torrent, [
            'name' => $this->name,
            'description' => $this->description ?: null,
        ]);

        session()->flash('status', 'Torrent updated successfully.');

        $this->redirect(route('torrents.show', $this->torrent), navigate: true);
    }

    public function render(): View
    {
        return $this->guiseView('guise::torrent.edit')
            ->title(__('Edit Torrent'));
    }
}
