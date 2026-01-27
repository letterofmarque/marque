<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <flux:button variant="ghost" :href="route('torrents.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </flux:button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="xl">{{ $torrent->name }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ __('Uploaded by :name :time', ['name' => $torrent->user->name, 'time' => $torrent->created_at->diffForHumans()]) }}
                </flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text class="text-sm text-zinc-500">{{ __('Size') }}</flux:text>
                    <flux:heading size="lg" class="mt-1">{{ $torrent->sizeForHumans() }}</flux:heading>
                </div>
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text class="text-sm text-zinc-500">{{ __('Files') }}</flux:text>
                    <flux:heading size="lg" class="mt-1">{{ $torrent->file_count }}</flux:heading>
                </div>
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text class="text-sm text-zinc-500">{{ __('Info Hash') }}</flux:text>
                    <flux:text class="mt-1 font-mono text-sm break-all">{{ $torrent->info_hash }}</flux:text>
                </div>
            </div>

            @if ($torrent->description)
                <div>
                    <flux:heading size="sm" class="mb-2">{{ __('Description') }}</flux:heading>
                    <flux:text class="whitespace-pre-wrap">{{ $torrent->description }}</flux:text>
                </div>
            @endif

            <div class="flex gap-2">
                @if ($torrent->torrent_file)
                    <flux:button variant="primary" icon="arrow-down-tray" :href="route('torrents.download', $torrent)">
                        {{ __('Download .torrent') }}
                    </flux:button>
                @endif

                @can('update', $torrent)
                    <flux:button variant="ghost" icon="pencil" :href="route('torrents.edit', $torrent)" wire:navigate>
                        {{ __('Edit') }}
                    </flux:button>
                @endcan
            </div>
        </div>
    </div>
</div>
