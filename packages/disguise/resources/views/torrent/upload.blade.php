<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-deck::button variant="ghost" :href="route('torrents.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-deck::button>
    </div>

    <div class="max-w-2xl">
        <x-deck::heading size="xl" class="mb-6">{{ __('Upload Torrent') }}</x-deck::heading>

        <form wire:submit="upload" class="flex flex-col gap-6">
            <x-deck::field :label="__('Torrent File')" name="torrentFile">
                <x-deck::input
                    type="file"
                    wire:model="torrentFile"
                    accept=".torrent"
                />
            </x-deck::field>

            <x-deck::field :label="__('Name')" name="name">
                <x-deck::input
                    wire:model="name"
                    placeholder="{{ __('Enter torrent name...') }}"
                />
            </x-deck::field>

            <x-deck::field :label="__('Description')" name="description">
                <x-deck::textarea
                    wire:model="description"
                    placeholder="{{ __('Optional description...') }}"
                    rows="4"
                />
            </x-deck::field>

            <div class="flex gap-2">
                <x-deck::button type="submit" variant="primary">
                    {{ __('Upload') }}
                </x-deck::button>
                <x-deck::button variant="ghost" :href="route('torrents.index')" wire:navigate>
                    {{ __('Cancel') }}
                </x-deck::button>
            </div>
        </form>
    </div>
</div>
