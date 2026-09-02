<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <x-ise::heading size="xl">{{ __('Torrents') }}</x-ise::heading>
        @if (auth()->user()->isUploader())
            <x-ise::button variant="primary" :href="route('torrents.upload')" icon="plus" wire:navigate>
                {{ __('Upload') }}
            </x-ise::button>
        @endif
    </div>

    <div class="flex items-center gap-4">
        <x-ise::input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search torrents...') }}"
            icon="magnifying-glass"
            class="max-w-sm"
        />

        @if ($canShowDead)
            <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                <input
                    type="checkbox"
                    wire:model.live="showDead"
                    class="rounded border-zinc-300 dark:border-zinc-600"
                />
                {{ __('Show dead torrents') }}
            </label>
        @endif
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <x-ise::table>
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Name') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Size') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Files') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Seeders') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Leechers') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Uploaded by') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($torrents as $torrent)
                    <tr wire:key="{{ $torrent->id }}">
                        <td class="px-3 py-2 font-medium">
                            <a href="{{ route('torrents.show', $torrent) }}" class="hover:underline" wire:navigate>
                                {{ $torrent->name }}
                            </a>
                        </td>
                        <td class="px-3 py-2">{{ $torrent->sizeForHumans() }}</td>
                        <td class="px-3 py-2">{{ $torrent->file_count }}</td>
                        <td class="px-3 py-2 {{ $torrent->seeders === 0 ? 'text-zinc-400 dark:text-zinc-500' : 'text-green-600 dark:text-green-400' }}">
                            {{ $torrent->seeders }}
                        </td>
                        <td class="px-3 py-2">{{ $torrent->leechers }}</td>
                        <td class="px-3 py-2">{{ $torrent->user->name }}</td>
                        <td class="px-3 py-2">{{ $torrent->created_at->diffForHumans() }}</td>
                        <td class="px-3 py-2">
                            <x-ise::button variant="ghost" size="sm" :href="route('torrents.show', $torrent)" wire:navigate>
                                {{ __('View') }}
                            </x-ise::button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-8 text-center">
                            <x-ise::text class="text-zinc-500">{{ __('No torrents found.') }}</x-ise::text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ise::table>
    </div>

    @if ($torrents->hasPages())
        <div class="mt-4">
            {{ $torrents->links() }}
        </div>
    @endif
</div>
