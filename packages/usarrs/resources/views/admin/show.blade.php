<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-deck::button variant="ghost" :href="route('admin.users.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-deck::button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4">
            <x-deck::heading size="xl">{{ $targetUser->name }}</x-deck::heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-deck::text class="text-sm text-zinc-500">{{ __('Email') }}</x-deck::text>
                    <x-deck::text>{{ $targetUser->email }}</x-deck::text>
                </div>
                <div>
                    <x-deck::text class="text-sm text-zinc-500">{{ __('Role') }}</x-deck::text>
                    <x-deck::text>{{ ucfirst($targetUser->role->value ?? $targetUser->role) }}</x-deck::text>
                </div>
                <div>
                    <x-deck::text class="text-sm text-zinc-500">{{ __('Status') }}</x-deck::text>
                    <x-deck::text>{{ ucfirst($targetUser->status ?? 'active') }}</x-deck::text>
                </div>
                <div>
                    <x-deck::text class="text-sm text-zinc-500">{{ __('Joined') }}</x-deck::text>
                    <x-deck::text>{{ $targetUser->created_at?->diffForHumans() ?? 'N/A' }}</x-deck::text>
                </div>
            </div>

            @if ($hasTrackerStats)
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <x-deck::text class="text-sm text-zinc-500">{{ __('Uploaded') }}</x-deck::text>
                        <x-deck::heading size="lg" class="mt-1">{{ Number::fileSize($targetUser->uploaded ?? 0) }}</x-deck::heading>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <x-deck::text class="text-sm text-zinc-500">{{ __('Downloaded') }}</x-deck::text>
                        <x-deck::heading size="lg" class="mt-1">{{ Number::fileSize($targetUser->downloaded ?? 0) }}</x-deck::heading>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <x-deck::text class="text-sm text-zinc-500">{{ __('Ratio') }}</x-deck::text>
                        <x-deck::heading size="lg" class="mt-1">{{ $targetUser->getRatio() }}</x-deck::heading>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if (auth()->user()->isAdmin() && auth()->id() !== $targetUser->getAuthIdentifier())
        <div class="flex flex-wrap gap-2">
            @if (($targetUser->status ?? 'active') !== 'banned')
                <x-deck::button variant="danger" wire:click="ban" wire:confirm="{{ __('Ban this user?') }}">
                    {{ __('Ban User') }}
                </x-deck::button>
            @else
                <x-deck::button variant="primary" wire:click="unban">
                    {{ __('Unban User') }}
                </x-deck::button>
            @endif

            @foreach ($roles as $role)
                @if ($role->value !== ($targetUser->role->value ?? $targetUser->role))
                    <x-deck::button variant="ghost" wire:click="setRole('{{ $role->value }}')" wire:confirm="{{ __('Set role to :role?', ['role' => $role->value]) }}">
                        {{ __('Set :role', ['role' => ucfirst($role->value)]) }}
                    </x-deck::button>
                @endif
            @endforeach
        </div>
    @endif
</div>
