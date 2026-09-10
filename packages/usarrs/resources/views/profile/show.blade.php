<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-deck::heading size="xl">{{ __('Profile') }}</x-deck::heading>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4">
            <div>
                <x-deck::text class="text-sm text-zinc-500">{{ __('Name') }}</x-deck::text>
                <x-deck::heading size="lg">{{ $user->name }}</x-deck::heading>
            </div>

            <div>
                <x-deck::text class="text-sm text-zinc-500">{{ __('Email') }}</x-deck::text>
                <x-deck::text>{{ $user->email }}</x-deck::text>
            </div>

            @if ($user->bio)
                <div>
                    <x-deck::text class="text-sm text-zinc-500">{{ __('Bio') }}</x-deck::text>
                    <x-deck::text class="whitespace-pre-wrap">{{ $user->bio }}</x-deck::text>
                </div>
            @endif

            <div>
                <x-deck::text class="text-sm text-zinc-500">{{ __('Role') }}</x-deck::text>
                <x-deck::text>{{ ucfirst($user->role->value ?? $user->role) }}</x-deck::text>
            </div>
        </div>
    </div>

    <div class="flex gap-2">
        <x-deck::button variant="primary" :href="route('profile.edit')" wire:navigate>
            {{ __('Edit Profile') }}
        </x-deck::button>
        @if ($hasTrackerStats)
            <x-deck::button variant="ghost" :href="route('profile.stats')" wire:navigate>
                {{ __('Tracker Stats') }}
            </x-deck::button>
        @endif
    </div>
</div>
