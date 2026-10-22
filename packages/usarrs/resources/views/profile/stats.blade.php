<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-deck::button variant="ghost" :href="route('profile.show')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-deck::button>
    </div>

    <x-deck::heading size="xl">{{ __('Tracker Stats') }}</x-deck::heading>

    @if ($stats)
        @include('usarrs::partials.tracker-figures', [
            'stats' => $stats,
            'itemClass' => 'rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900',
        ])
    @endif

    @if ($showAnnounceKey)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-deck::heading size="sm" class="mb-4">{{ __('Announce Key') }}</x-deck::heading>
            @include('usarrs::partials.announce-key', ['announceKey' => $announceKey, 'allowRegen' => $allowRegen, 'addressUnproven' => $addressUnproven])
        </div>
    @endif

    @if ($seedboxesAvailable)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-deck::heading size="sm" class="mb-2">{{ __('Seedboxes') }}</x-deck::heading>
            <x-deck::text class="mb-4">{{ __('Seed from a seedbox? Register it to be held to the seedbox speed limit.') }}</x-deck::text>
            <x-deck::button variant="ghost" :href="route('profile.seedboxes')" wire:navigate>{{ __('Manage seedboxes') }}</x-deck::button>
        </div>
    @endif

    @if ($sessionsAvailable)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-deck::heading size="sm" class="mb-2">{{ __('Sessions') }}</x-deck::heading>
            <x-deck::text class="mb-4">{{ __('Restarted a client somewhere else and been refused? End the old session here.') }}</x-deck::text>
            <x-deck::button variant="ghost" :href="route('profile.sessions')" wire:navigate>{{ __('Manage sessions') }}</x-deck::button>
        </div>
    @endif

    {{-- What the member owes under the tracker's hit-and-run rule (Job #256). --}}
    @if ($obligations !== [])
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-deck::heading size="sm" class="mb-2">{{ __('Seeding owed') }}</x-deck::heading>
            <x-deck::text class="mb-4 text-sm text-zinc-500">{{ __('Torrents you downloaded and still owe seeding on. Seed each one to the time shown, or to the ratio, whichever comes first.') }}</x-deck::text>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($obligations as $obligation)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                        <span class="min-w-0 break-words font-medium">
                            @if (Route::has('torrents.show'))
                                <a href="{{ route('torrents.show', $obligation->torrentId) }}" class="underline">{{ $obligation->torrentName }}</a>
                            @else
                                {{ $obligation->torrentName }}
                            @endif
                        </span>
                        <span class="text-sm text-zinc-500">
                            {{ __(':seeded of :required seeded', ['seeded' => \Marque\Usarrs\Support\Duration::short($obligation->seededSeconds), 'required' => \Marque\Usarrs\Support\Duration::short($obligation->requiredSeconds)]) }}@if ($obligation->requiredRatio !== null){{ __(', or ratio :ratio of :required', ['ratio' => number_format((float) $obligation->ratio, 2), 'required' => number_format($obligation->requiredRatio, 2)]) }}@endif
                        </span>
                        @if ($obligation->state === \Marque\Trove\Enums\ObligationState::Struck)
                            <span class="w-full text-sm text-red-700 dark:text-red-300">{{ __('Hit-and-run strike, lapses :date. Seed it to clear it now.', ['date' => $obligation->until?->toFormattedDayDateString()]) }}</span>
                        @elseif ($obligation->state === \Marque\Trove\Enums\ObligationState::Prewarned)
                            <span class="w-full text-sm text-amber-700 dark:text-amber-300">{{ __('Warned: start seeding it again, or it becomes a hit-and-run strike.') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
        </div>
    @endif
</div>
