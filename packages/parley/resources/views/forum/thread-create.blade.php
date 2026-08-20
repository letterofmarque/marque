<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-ise::button variant="ghost" :href="route('parley.forum.categories.show', $category)" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-ise::button>
    </div>

    <div class="max-w-2xl">
        <x-ise::heading size="xl" class="mb-6">{{ __('New Thread in :category', ['category' => $category->name]) }}</x-ise::heading>

        <form wire:submit="submit" class="flex flex-col gap-6">
            <x-ise::field :label="__('Title')" name="title">
                <x-ise::input wire:model="title" placeholder="{{ __('Thread title…') }}" />
            </x-ise::field>

            <x-ise::field :label="__('Message')" name="body">
                <x-ise::textarea wire:model="body" rows="8" placeholder="{{ __('Write your post…') }}" />
            </x-ise::field>

            <div class="flex gap-2">
                <x-ise::button type="submit" variant="primary">{{ __('Post Thread') }}</x-ise::button>
                <x-ise::button variant="ghost" :href="route('parley.forum.categories.show', $category)" wire:navigate>
                    {{ __('Cancel') }}
                </x-ise::button>
            </div>
        </form>
    </div>
</div>
