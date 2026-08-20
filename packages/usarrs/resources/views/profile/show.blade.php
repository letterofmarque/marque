<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-ise::heading size="xl">{{ __('Profile') }}</x-ise::heading>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4">
            <div>
                <x-ise::text class="text-sm text-zinc-500">{{ __('Name') }}</x-ise::text>
                <x-ise::heading size="lg">{{ $user->name }}</x-ise::heading>
            </div>

            <div>
                <x-ise::text class="text-sm text-zinc-500">{{ __('Email') }}</x-ise::text>
                <x-ise::text>{{ $user->email }}</x-ise::text>
            </div>

            @if ($user->bio)
                <div>
                    <x-ise::text class="text-sm text-zinc-500">{{ __('Bio') }}</x-ise::text>
                    <x-ise::text class="whitespace-pre-wrap">{{ $user->bio }}</x-ise::text>
                </div>
            @endif

            <div>
                <x-ise::text class="text-sm text-zinc-500">{{ __('Role') }}</x-ise::text>
                <x-ise::text>{{ ucfirst($user->role->value ?? $user->role) }}</x-ise::text>
            </div>
        </div>
    </div>

    <div class="flex gap-2">
        <x-ise::button variant="primary" :href="route('profile.edit')" wire:navigate>
            {{ __('Edit Profile') }}
        </x-ise::button>
        @if ($hasTrackerStats)
            <x-ise::button variant="ghost" :href="route('profile.stats')" wire:navigate>
                {{ __('Tracker Stats') }}
            </x-ise::button>
        @endif
    </div>
</div>
