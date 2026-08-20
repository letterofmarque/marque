<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-ise::button variant="ghost" :href="route('torrents.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-ise::button>
    </div>

    <div class="max-w-2xl">
        <x-ise::heading size="xl" class="mb-6">{{ __('Upload Torrent') }}</x-ise::heading>

        <form wire:submit="upload" class="flex flex-col gap-6">
            <x-ise::field :label="__('Torrent File')" name="torrentFile">
                <x-ise::input
                    type="file"
                    wire:model="torrentFile"
                    accept=".torrent"
                />
            </x-ise::field>

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

            <div class="flex gap-2">
                <x-ise::button type="submit" variant="primary">
                    {{ __('Upload') }}
                </x-ise::button>
                <x-ise::button variant="ghost" :href="route('torrents.index')" wire:navigate>
                    {{ __('Cancel') }}
                </x-ise::button>
            </div>
        </form>
    </div>
</div>
