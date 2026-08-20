<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-ise::button variant="ghost" :href="route('torrents.show', $torrent)" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-ise::button>
    </div>

    <div class="max-w-2xl">
        <x-ise::heading size="xl" class="mb-6">{{ __('Edit Torrent') }}</x-ise::heading>

        <form wire:submit="save" class="flex flex-col gap-6">
            <x-ise::field :label="__('Name')" name="name">
                <x-ise::input
                    wire:model="name"
                    placeholder="{{ __('Enter torrent name...') }}"
                />
            </x-ise::field>

            <x-ise::field :label="__('Description')" name="description">
                <x-ise::textarea
                    wire:model="description"
                    placeholder="{{ __('Optional description...') }}"
                    rows="4"
                />
            </x-ise::field>

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <x-ise::text class="text-sm text-zinc-500">
                    {{ __('Info hash, size, and file count cannot be changed as they are derived from the torrent file.') }}
                </x-ise::text>
            </div>

            <div class="flex gap-2">
                <x-ise::button type="submit" variant="primary">
                    {{ __('Save Changes') }}
                </x-ise::button>
                <x-ise::button variant="ghost" :href="route('torrents.show', $torrent)" wire:navigate>
                    {{ __('Cancel') }}
                </x-ise::button>
            </div>
        </form>
    </div>
</div>
