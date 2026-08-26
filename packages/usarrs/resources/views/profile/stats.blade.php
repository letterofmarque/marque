<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-ise::button variant="ghost" :href="route('profile.show')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-ise::button>
    </div>

    <x-ise::heading size="xl">{{ __('Tracker Stats') }}</x-ise::heading>

    @if ($hasTrackerStats)
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-ise::text class="text-sm text-zinc-500">{{ __('Uploaded') }}</x-ise::text>
                <x-ise::heading size="lg" class="mt-1">{{ Number::fileSize($user->uploaded ?? 0) }}</x-ise::heading>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-ise::text class="text-sm text-zinc-500">{{ __('Downloaded') }}</x-ise::text>
                <x-ise::heading size="lg" class="mt-1">{{ Number::fileSize($user->downloaded ?? 0) }}</x-ise::heading>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-ise::text class="text-sm text-zinc-500">{{ __('Ratio') }}</x-ise::text>
                <x-ise::heading size="lg" class="mt-1">{{ $user->getRatio() }}</x-ise::heading>
            </div>
        </div>
    @endif

    @if ($showAnnounceKey)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-ise::heading size="sm" class="mb-4">{{ __('Announce Key') }}</x-ise::heading>
            <div class="flex items-center gap-4">
                <code class="rounded bg-zinc-100 px-3 py-2 font-mono text-sm dark:bg-zinc-800">{{ $user->announce_key }}</code>
                @if ($allowRegen)
                    <x-ise::button
                        variant="ghost"
                        size="sm"
                        wire:click="regenerateAnnounceKey"
                        wire:confirm="{{ __('Are you sure? All active torrents will need to be re-downloaded.') }}"
                    >
                        {{ __('Regenerate') }}
                    </x-ise::button>
                @endif
            </div>
            <x-ise::text class="mt-2 text-sm text-zinc-500">
                {{ __('Your announce key is used in tracker URLs. Do not share it.') }}
            </x-ise::text>
        </div>
    @endif

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-ise::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-ise::text>
        </div>
    @endif
</div>
