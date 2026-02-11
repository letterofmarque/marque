<?php

declare(strict_types=1);

namespace Marque\Disguise\Livewire\Torrent;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Marque\Disguise\Livewire\Component;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Services\TorrentService;

#[Title('Upload Torrent')]
class Upload extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public function mount(): void
    {
        abort_unless(config('disguise.allow_upload', true), 404);
        $this->authorize('create', Torrent::class);
    }

    #[Validate('required|file|max:2048')]
    public ?TemporaryUploadedFile $torrentFile = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:10000')]
    public string $description = '';

    public function upload(TorrentService $service): void
    {
        $this->validate();

        $service->createFromUpload(
            file: $this->torrentFile,
            user: auth()->user(),
            name: $this->name,
            description: $this->description ?: null,
        );

        session()->flash('status', 'Torrent uploaded successfully.');

        $this->redirect(route('torrents.index'), navigate: true);
    }

    public function render(): View
    {
        return $this->disguiseView('disguise::torrent.upload');
    }
}
