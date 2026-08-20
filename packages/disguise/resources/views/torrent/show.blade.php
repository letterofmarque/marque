<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-ise::button variant="ghost" :href="route('torrents.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-ise::button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-6">
            <div>
                <x-ise::heading size="xl">{{ $torrent->name }}</x-ise::heading>
                <x-ise::text class="mt-1 text-zinc-500">
                    {{ __('Uploaded by :name :time', ['name' => $torrent->user->name, 'time' => $torrent->created_at->diffForHumans()]) }}
                </x-ise::text>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <x-ise::text class="text-sm text-zinc-500">{{ __('Size') }}</x-ise::text>
                    <x-ise::heading size="lg" class="mt-1">{{ $torrent->sizeForHumans() }}</x-ise::heading>
                </div>
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <x-ise::text class="text-sm text-zinc-500">{{ __('Files') }}</x-ise::text>
                    <x-ise::heading size="lg" class="mt-1">{{ $torrent->file_count }}</x-ise::heading>
                </div>
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <x-ise::text class="text-sm text-zinc-500">{{ __('Info Hash') }}</x-ise::text>
                    <x-ise::text class="mt-1 font-mono text-sm break-all">{{ $torrent->info_hash }}</x-ise::text>
                </div>
            </div>

            @if ($torrent->description)
                <div>
                    <x-ise::heading size="sm" class="mb-2">{{ __('Description') }}</x-ise::heading>
                    <x-ise::text class="whitespace-pre-wrap">{{ $torrent->description }}</x-ise::text>
                </div>
            @endif

            <div class="flex gap-2">
                @if ($torrent->torrent_file && config('disguise.allow_download', true))
                    <x-ise::button variant="primary" icon="arrow-down-tray" :href="route('torrents.download', $torrent)">
                        {{ __('Download .torrent') }}
                    </x-ise::button>
                @endif

                @auth
                    @can('update', $torrent)
                        <x-ise::button variant="ghost" icon="pencil" :href="route('torrents.edit', $torrent)" wire:navigate>
                            {{ __('Edit') }}
                        </x-ise::button>
                    @endcan
                @endauth
            </div>
        </div>
    </div>
</div>
