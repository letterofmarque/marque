<div>
    @if ($stats)
        @include('usarrs::partials.tracker-figures', ['stats' => $stats])

        <div class="mt-4">
            <x-deck::button variant="ghost" size="sm" :href="route('profile.stats')" wire:navigate>
                {{ __('Details') }}
            </x-deck::button>
        </div>
    @endif
</div>
