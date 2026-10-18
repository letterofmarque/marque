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

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
        </div>
    @endif
</div>
