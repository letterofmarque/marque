<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-id::button variant="ghost" :href="route('torrents.show', $torrent)" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-id::button>
    </div>

    <div class="max-w-2xl">
        <x-id::heading size="xl" class="mb-6">{{ __('Edit Torrent') }}</x-id::heading>

        <form wire:submit="save" class="flex flex-col gap-6">
            <x-id::field :label="__('Name')" name="name">
                <x-id::input
                    wire:model="name"
                    placeholder="{{ __('Enter torrent name...') }}"
                />
            </x-id::field>

            <x-id::field :label="__('Description')" name="description">
                <x-id::textarea
                    wire:model="description"
                    placeholder="{{ __('Optional description...') }}"
                    rows="4"
                />
            </x-id::field>

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <x-id::text class="text-sm text-zinc-500">
                    {{ __('Info hash, size, and file count cannot be changed as they are derived from the torrent file.') }}
                </x-id::text>
            </div>

            <div class="flex gap-2">
                <x-id::button type="submit" variant="primary">
                    {{ __('Save Changes') }}
                </x-id::button>
                <x-id::button variant="ghost" :href="route('torrents.show', $torrent)" wire:navigate>
                    {{ __('Cancel') }}
                </x-id::button>
            </div>
        </form>
    </div>
</div>
