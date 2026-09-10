<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-deck::button variant="ghost" :href="route('profile.show')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-deck::button>
    </div>

    <x-deck::heading size="xl">{{ __('Tracker Stats') }}</x-deck::heading>

    @if ($hasTrackerStats)
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-deck::text class="text-sm text-zinc-500">{{ __('Uploaded') }}</x-deck::text>
                <x-deck::heading size="lg" class="mt-1">{{ Number::fileSize($user->uploaded ?? 0) }}</x-deck::heading>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-deck::text class="text-sm text-zinc-500">{{ __('Downloaded') }}</x-deck::text>
                <x-deck::heading size="lg" class="mt-1">{{ Number::fileSize($user->downloaded ?? 0) }}</x-deck::heading>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-deck::text class="text-sm text-zinc-500">{{ __('Ratio') }}</x-deck::text>
                <x-deck::heading size="lg" class="mt-1">{{ $user->getRatio() }}</x-deck::heading>
            </div>
        </div>
    @endif

    @if ($showAnnounceKey)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-deck::heading size="sm" class="mb-4">{{ __('Announce Key') }}</x-deck::heading>
            <div class="flex items-center gap-4">
                <code class="rounded bg-zinc-100 px-3 py-2 font-mono text-sm dark:bg-zinc-800">{{ $user->announce_key }}</code>
                @if ($allowRegen)
                    <x-deck::button
                        variant="ghost"
                        size="sm"
                        wire:click="regenerateAnnounceKey"
                        wire:confirm="{{ __('Are you sure? All active torrents will need to be re-downloaded.') }}"
                    >
                        {{ __('Regenerate') }}
                    </x-deck::button>
                @endif
            </div>
            <x-deck::text class="mt-2 text-sm text-zinc-500">
                {{ __('Your announce key is used in tracker URLs. Do not share it.') }}
            </x-deck::text>
        </div>
    @endif

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
        </div>
    @endif
</div>
