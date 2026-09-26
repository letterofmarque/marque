<div class="flex flex-col gap-3">
    <x-deck::heading size="lg">{{ __(':available of :max available', ['available' => $available, 'max' => $max]) }}</x-deck::heading>
    <x-deck::text class="text-sm text-zinc-500">{{ __(':pending pending', ['pending' => $pending]) }}</x-deck::text>

    <div class="mt-1 flex gap-2">
        @if ($canCreate)
            <x-deck::button variant="primary" size="sm" :href="route('invites.create')" wire:navigate>
                {{ __('Send an invite') }}
            </x-deck::button>
        @endif
        <x-deck::button variant="ghost" size="sm" :href="route('invites.index')" wire:navigate>
            {{ __('Manage') }}
        </x-deck::button>
    </div>
</div>
