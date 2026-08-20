<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-id::button variant="ghost" :href="route('parley.forum.categories.show', $category)" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-id::button>
    </div>

    <div class="max-w-2xl">
        <x-id::heading size="xl" class="mb-6">{{ __('New Thread in :category', ['category' => $category->name]) }}</x-id::heading>

        <form wire:submit="submit" class="flex flex-col gap-6">
            <x-id::field :label="__('Title')" name="title">
                <x-id::input wire:model="title" placeholder="{{ __('Thread title…') }}" />
            </x-id::field>

            <x-id::field :label="__('Message')" name="body">
                <x-id::textarea wire:model="body" rows="8" placeholder="{{ __('Write your post…') }}" />
            </x-id::field>

            <div class="flex gap-2">
                <x-id::button type="submit" variant="primary">{{ __('Post Thread') }}</x-id::button>
                <x-id::button variant="ghost" :href="route('parley.forum.categories.show', $category)" wire:navigate>
                    {{ __('Cancel') }}
                </x-id::button>
            </div>
        </form>
    </div>
</div>
