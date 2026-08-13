<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-id::button variant="ghost" :href="route('profile.show')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-id::button>
    </div>

    <x-id::heading size="xl">{{ __('Tracker Stats') }}</x-id::heading>

    @if ($hasTrackerStats)
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-id::text class="text-sm text-zinc-500">{{ __('Uploaded') }}</x-id::text>
                <x-id::heading size="lg" class="mt-1">{{ Number::fileSize($user->uploaded ?? 0) }}</x-id::heading>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-id::text class="text-sm text-zinc-500">{{ __('Downloaded') }}</x-id::text>
                <x-id::heading size="lg" class="mt-1">{{ Number::fileSize($user->downloaded ?? 0) }}</x-id::heading>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <x-id::text class="text-sm text-zinc-500">{{ __('Ratio') }}</x-id::text>
                <x-id::heading size="lg" class="mt-1">{{ $user->getRatio() }}</x-id::heading>
            </div>
        </div>
    @endif

    @if ($showPasskey)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <x-id::heading size="sm" class="mb-4">{{ __('Passkey') }}</x-id::heading>
            <div class="flex items-center gap-4">
                <code class="rounded bg-zinc-100 px-3 py-2 font-mono text-sm dark:bg-zinc-800">{{ $user->passkey }}</code>
                @if ($allowRegen)
                    <x-id::button
                        variant="ghost"
                        size="sm"
                        wire:click="regeneratePasskey"
                        wire:confirm="{{ __('Are you sure? All active torrents will need to be re-downloaded.') }}"
                    >
                        {{ __('Regenerate') }}
                    </x-id::button>
                @endif
            </div>
            <x-id::text class="mt-2 text-sm text-zinc-500">
                {{ __('Your passkey is used in tracker URLs. Do not share it.') }}
            </x-id::text>
        </div>
    @endif

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-id::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-id::text>
        </div>
    @endif
</div>
