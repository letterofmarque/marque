<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-id::button variant="ghost" :href="route('torrents.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-id::button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-6">
            <div>
                <x-id::heading size="xl">{{ $torrent->name }}</x-id::heading>
                <x-id::text class="mt-1 text-zinc-500">
                    {{ __('Uploaded by :name :time', ['name' => $torrent->user->name, 'time' => $torrent->created_at->diffForHumans()]) }}
                </x-id::text>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <x-id::text class="text-sm text-zinc-500">{{ __('Size') }}</x-id::text>
                    <x-id::heading size="lg" class="mt-1">{{ $torrent->sizeForHumans() }}</x-id::heading>
                </div>
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <x-id::text class="text-sm text-zinc-500">{{ __('Files') }}</x-id::text>
                    <x-id::heading size="lg" class="mt-1">{{ $torrent->file_count }}</x-id::heading>
                </div>
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <x-id::text class="text-sm text-zinc-500">{{ __('Info Hash') }}</x-id::text>
                    <x-id::text class="mt-1 font-mono text-sm break-all">{{ $torrent->info_hash }}</x-id::text>
                </div>
            </div>

            @if ($torrent->description)
                <div>
                    <x-id::heading size="sm" class="mb-2">{{ __('Description') }}</x-id::heading>
                    <x-id::text class="whitespace-pre-wrap">{{ $torrent->description }}</x-id::text>
                </div>
            @endif

            <div class="flex gap-2">
                @if ($torrent->torrent_file)
                    <x-id::button variant="primary" icon="arrow-down-tray" :href="route('torrents.download', $torrent)">
                        {{ __('Download .torrent') }}
                    </x-id::button>
                @endif

                @can('update', $torrent)
                    <x-id::button variant="ghost" icon="pencil" :href="route('torrents.edit', $torrent)" wire:navigate>
                        {{ __('Edit') }}
                    </x-id::button>
                @endcan
            </div>
        </div>
    </div>
</div>
