<div>
    @if ($stats)
        @include('usarrs::partials.tracker-figures', ['stats' => $stats])

        @if ($obligations !== [])
            @php($struck = count(array_filter($obligations, fn ($o) => $o->state === \Marque\Trove\Enums\ObligationState::Struck)))
            <x-deck::text class="mt-4 text-sm">
                {{ trans_choice('Seeding owed on :count torrent|Seeding owed on :count torrents', count($obligations)) }}@if ($struck > 0), {{ trans_choice(':count hit-and-run strike|:count hit-and-run strikes', $struck) }}@endif.
                <a href="{{ route('profile.stats') }}" class="underline" wire:navigate>{{ __('See which') }}</a>
            </x-deck::text>
        @endif

        <div class="mt-4">
            <x-deck::button variant="ghost" size="sm" :href="route('profile.stats')" wire:navigate>
                {{ __('Details') }}
            </x-deck::button>
        </div>
    @endif
</div>
