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
use Marque\Trove\Services\TorrentFileService;
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

    public function upload(TorrentService $service, TorrentFileService $files): void
    {
        $this->validate();

        // The tracker's rules (private flag, v1) before anything is stored; a
        // refusal names the fix, a warning rides along with success (#10947).
        $inspection = $files->inspect((string) file_get_contents($this->torrentFile->getRealPath()));

        if ($inspection->refused()) {
            $this->addError('torrentFile', implode(' ', $inspection->refusals));

            return;
        }

        $service->createFromUpload(
            file: $this->torrentFile,
            user: auth()->user(),
            name: $this->name,
            description: $this->description ?: null,
        );

        session()->flash('status', trim('Torrent uploaded successfully. '.implode(' ', $inspection->warnings)));

        $this->redirect(route('torrents.index'), navigate: true);
    }

    public function render(): View
    {
        return $this->disguiseView('disguise::torrent.upload');
    }
}
