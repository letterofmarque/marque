<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading size="xl">{{ __('Profile') }}</flux:heading>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4">
            <div>
                <flux:text class="text-sm text-zinc-500">{{ __('Name') }}</flux:text>
                <flux:heading size="lg">{{ $user->name }}</flux:heading>
            </div>

            <div>
                <flux:text class="text-sm text-zinc-500">{{ __('Email') }}</flux:text>
                <flux:text>{{ $user->email }}</flux:text>
            </div>

            @if ($user->bio)
                <div>
                    <flux:text class="text-sm text-zinc-500">{{ __('Bio') }}</flux:text>
                    <flux:text class="whitespace-pre-wrap">{{ $user->bio }}</flux:text>
                </div>
            @endif

            <div>
                <flux:text class="text-sm text-zinc-500">{{ __('Role') }}</flux:text>
                <flux:text>{{ ucfirst($user->role->value ?? $user->role) }}</flux:text>
            </div>
        </div>
    </div>

    <div class="flex gap-2">
        <flux:button variant="primary" :href="route('profile.edit')" wire:navigate>
            {{ __('Edit Profile') }}
        </flux:button>
        @if ($hasTrackerStats)
            <flux:button variant="ghost" :href="route('profile.stats')" wire:navigate>
                {{ __('Tracker Stats') }}
            </flux:button>
        @endif
    </div>
</div>
