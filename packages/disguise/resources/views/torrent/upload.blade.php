<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-id::button variant="ghost" :href="route('torrents.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-id::button>
    </div>

    <div class="max-w-2xl">
        <x-id::heading size="xl" class="mb-6">{{ __('Upload Torrent') }}</x-id::heading>

        <form wire:submit="upload" class="flex flex-col gap-6">
            <x-id::field :label="__('Torrent File')" name="torrentFile">
                <x-id::input
                    type="file"
                    wire:model="torrentFile"
                    accept=".torrent"
                />
            </x-id::field>

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

            <div class="flex gap-2">
                <x-id::button type="submit" variant="primary">
                    {{ __('Upload') }}
                </x-id::button>
                <x-id::button variant="ghost" :href="route('torrents.index')" wire:navigate>
                    {{ __('Cancel') }}
                </x-id::button>
            </div>
        </form>
    </div>
</div>
