<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-deck::button variant="ghost" :href="route('parley.forum.categories.show', $category)" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-deck::button>
    </div>

    <div class="max-w-2xl">
        <x-deck::heading size="xl" class="mb-6">{{ __('New Thread in :category', ['category' => $category->name]) }}</x-deck::heading>

        <form wire:submit="submit" class="flex flex-col gap-6">
            <x-deck::field :label="__('Title')" name="title">
                <x-deck::input wire:model="title" placeholder="{{ __('Thread title…') }}" />
            </x-deck::field>

            <x-deck::field :label="__('Message')" name="body">
                <x-deck::textarea wire:model="body" rows="8" placeholder="{{ __('Write your post…') }}" />
            </x-deck::field>

            <div class="flex gap-2">
                <x-deck::button type="submit" variant="primary">{{ __('Post Thread') }}</x-deck::button>
                <x-deck::button variant="ghost" :href="route('parley.forum.categories.show', $category)" wire:navigate>
                    {{ __('Cancel') }}
                </x-deck::button>
            </div>
        </form>
    </div>
</div>
