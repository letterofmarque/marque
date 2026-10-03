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

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
        </div>
    @endif
</div>
