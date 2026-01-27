<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <flux:button variant="ghost" :href="route('torrents.show', $torrent)" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </flux:button>
    </div>

    <div class="max-w-2xl">
        <flux:heading size="xl" class="mb-6">{{ __('Edit Torrent') }}</flux:heading>

        <form wire:submit="save" class="flex flex-col gap-6">
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

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text class="text-sm text-zinc-500">
                    {{ __('Info hash, size, and file count cannot be changed as they are derived from the torrent file.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">
                    {{ __('Save Changes') }}
                </flux:button>
                <flux:button variant="ghost" :href="route('torrents.show', $torrent)" wire:navigate>
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </form>
    </div>
</div>
