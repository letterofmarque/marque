<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-id::heading size="xl">{{ __('Profile') }}</x-id::heading>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4">
            <div>
                <x-id::text class="text-sm text-zinc-500">{{ __('Name') }}</x-id::text>
                <x-id::heading size="lg">{{ $user->name }}</x-id::heading>
            </div>

            <div>
                <x-id::text class="text-sm text-zinc-500">{{ __('Email') }}</x-id::text>
                <x-id::text>{{ $user->email }}</x-id::text>
            </div>

            @if ($user->bio)
                <div>
                    <x-id::text class="text-sm text-zinc-500">{{ __('Bio') }}</x-id::text>
                    <x-id::text class="whitespace-pre-wrap">{{ $user->bio }}</x-id::text>
                </div>
            @endif

            <div>
                <x-id::text class="text-sm text-zinc-500">{{ __('Role') }}</x-id::text>
                <x-id::text>{{ ucfirst($user->role->value ?? $user->role) }}</x-id::text>
            </div>
        </div>
    </div>

    <div class="flex gap-2">
        <x-id::button variant="primary" :href="route('profile.edit')" wire:navigate>
            {{ __('Edit Profile') }}
        </x-id::button>
        @if ($hasTrackerStats)
            <x-id::button variant="ghost" :href="route('profile.stats')" wire:navigate>
                {{ __('Tracker Stats') }}
            </x-id::button>
        @endif
    </div>
</div>
