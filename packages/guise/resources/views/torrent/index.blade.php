<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Torrents') }}</flux:heading>
        @if (auth()->user()->isUploader())
            <flux:button variant="primary" :href="route('torrents.upload')" icon="plus" wire:navigate>
                {{ __('Upload') }}
            </flux:button>
        @endif
    </div>

    <div class="flex items-center gap-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search torrents...') }}"
            icon="magnifying-glass"
            class="max-w-sm"
        />
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Size') }}</flux:table.column>
                <flux:table.column>{{ __('Files') }}</flux:table.column>
                <flux:table.column>{{ __('Uploaded by') }}</flux:table.column>
                <flux:table.column>{{ __('Date') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($torrents as $torrent)
                    <flux:table.row :key="$torrent->id">
                        <flux:table.cell class="font-medium">
                            <a href="{{ route('torrents.show', $torrent) }}" class="hover:underline" wire:navigate>
                                {{ $torrent->name }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $torrent->sizeForHumans() }}</flux:table.cell>
                        <flux:table.cell>{{ $torrent->file_count }}</flux:table.cell>
                        <flux:table.cell>{{ $torrent->user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $torrent->created_at->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button variant="ghost" size="sm" :href="route('torrents.show', $torrent)" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center py-8">
                            <flux:text class="text-zinc-500">{{ __('No torrents found.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($torrents->hasPages())
        <div class="mt-4">
            {{ $torrents->links() }}
        </div>
    @endif
</div>
