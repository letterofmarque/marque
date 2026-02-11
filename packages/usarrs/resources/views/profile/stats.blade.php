<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <flux:button variant="ghost" :href="route('profile.show')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </flux:button>
    </div>

    <flux:heading size="xl">{{ __('Tracker Stats') }}</flux:heading>

    @if ($hasTrackerStats)
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text class="text-sm text-zinc-500">{{ __('Uploaded') }}</flux:text>
                <flux:heading size="lg" class="mt-1">{{ Number::fileSize($user->uploaded ?? 0) }}</flux:heading>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text class="text-sm text-zinc-500">{{ __('Downloaded') }}</flux:text>
                <flux:heading size="lg" class="mt-1">{{ Number::fileSize($user->downloaded ?? 0) }}</flux:heading>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text class="text-sm text-zinc-500">{{ __('Ratio') }}</flux:text>
                <flux:heading size="lg" class="mt-1">{{ $user->getRatio() }}</flux:heading>
            </div>
        </div>
    @endif

    @if ($showPasskey)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="sm" class="mb-4">{{ __('Passkey') }}</flux:heading>
            <div class="flex items-center gap-4">
                <code class="rounded bg-zinc-100 px-3 py-2 font-mono text-sm dark:bg-zinc-800">{{ $user->passkey }}</code>
                @if ($allowRegen)
                    <flux:button
                        variant="ghost"
                        size="sm"
                        wire:click="regeneratePasskey"
                        wire:confirm="{{ __('Are you sure? All active torrents will need to be re-downloaded.') }}"
                    >
                        {{ __('Regenerate') }}
                    </flux:button>
                @endif
            </div>
            <flux:text class="mt-2 text-sm text-zinc-500">
                {{ __('Your passkey is used in tracker URLs. Do not share it.') }}
            </flux:text>
        </div>
    @endif

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <flux:text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</flux:text>
        </div>
    @endif
</div>
