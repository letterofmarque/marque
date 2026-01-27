<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <flux:button variant="ghost" :href="route('torrents.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </flux:button>
    </div>

    <div class="max-w-2xl">
        <flux:heading size="xl" class="mb-6">{{ __('Upload Torrent') }}</flux:heading>

        <form wire:submit="upload" class="flex flex-col gap-6">
            <flux:field>
                <flux:label>{{ __('Torrent File') }}</flux:label>
                <flux:input
                    type="file"
                    wire:model="torrentFile"
                    accept=".torrent"
                />
                <flux:error name="torrentFile" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input
                    wire:model="name"
                    placeholder="{{ __('Enter torrent name...') }}"
                />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea
                    wire:model="description"
                    placeholder="{{ __('Optional description...') }}"
                    rows="4"
                />
                <flux:error name="description" />
            </flux:field>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">
                    {{ __('Upload') }}
                </flux:button>
                <flux:button variant="ghost" :href="route('torrents.index')" wire:navigate>
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </form>
    </div>
</div>
