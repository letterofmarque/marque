<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-deck::button variant="ghost" :href="route('torrents.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-deck::button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-6">
            <div>
                <x-deck::heading size="xl">{{ $torrent->name }}</x-deck::heading>
                <x-deck::text class="mt-1 text-zinc-500">
                    {{ __('Uploaded by :name :time', ['name' => $torrent->user->name, 'time' => $torrent->created_at->diffForHumans()]) }}
                </x-deck::text>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <x-deck::text class="text-sm text-zinc-500">{{ __('Size') }}</x-deck::text>
                    <x-deck::heading size="lg" class="mt-1">{{ $torrent->sizeForHumans() }}</x-deck::heading>
                </div>
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <x-deck::text class="text-sm text-zinc-500">{{ __('Files') }}</x-deck::text>
                    <x-deck::heading size="lg" class="mt-1">{{ $torrent->file_count }}</x-deck::heading>
                </div>
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <x-deck::text class="text-sm text-zinc-500">{{ __('Info Hash') }}</x-deck::text>
                    <x-deck::text class="mt-1 font-mono text-sm break-all">{{ $torrent->info_hash }}</x-deck::text>
                </div>
            </div>

            @if ($torrent->description)
                <div>
                    <x-deck::heading size="sm" class="mb-2">{{ __('Description') }}</x-deck::heading>
                    <x-deck::text class="whitespace-pre-wrap">{{ $torrent->description }}</x-deck::text>
                </div>
            @endif

            <div class="flex gap-2">
                @if ($torrent->torrent_file && config('disguise.allow_download', true))
                    <x-deck::button variant="primary" icon="arrow-down-tray" :href="route('torrents.download', $torrent)">
                        {{ __('Download .torrent') }}
                    </x-deck::button>
                @endif

                @auth
                    @can('update', $torrent)
                        <x-deck::button variant="ghost" icon="pencil" :href="route('torrents.edit', $torrent)" wire:navigate>
                            {{ __('Edit') }}
                        </x-deck::button>
                    @endcan
                @endauth
            </div>
        </div>
    </div>
</div>
