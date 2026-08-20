<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-ise::button variant="ghost" :href="route('admin.users.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-ise::button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4">
            <x-ise::heading size="xl">{{ $targetUser->name }}</x-ise::heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-ise::text class="text-sm text-zinc-500">{{ __('Email') }}</x-ise::text>
                    <x-ise::text>{{ $targetUser->email }}</x-ise::text>
                </div>
                <div>
                    <x-ise::text class="text-sm text-zinc-500">{{ __('Role') }}</x-ise::text>
                    <x-ise::text>{{ ucfirst($targetUser->role->value ?? $targetUser->role) }}</x-ise::text>
                </div>
                <div>
                    <x-ise::text class="text-sm text-zinc-500">{{ __('Status') }}</x-ise::text>
                    <x-ise::text>{{ ucfirst($targetUser->status ?? 'active') }}</x-ise::text>
                </div>
                <div>
                    <x-ise::text class="text-sm text-zinc-500">{{ __('Joined') }}</x-ise::text>
                    <x-ise::text>{{ $targetUser->created_at?->diffForHumans() ?? 'N/A' }}</x-ise::text>
                </div>
            </div>

            @if ($hasTrackerStats)
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <x-ise::text class="text-sm text-zinc-500">{{ __('Uploaded') }}</x-ise::text>
                        <x-ise::heading size="lg" class="mt-1">{{ Number::fileSize($targetUser->uploaded ?? 0) }}</x-ise::heading>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <x-ise::text class="text-sm text-zinc-500">{{ __('Downloaded') }}</x-ise::text>
                        <x-ise::heading size="lg" class="mt-1">{{ Number::fileSize($targetUser->downloaded ?? 0) }}</x-ise::heading>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <x-ise::text class="text-sm text-zinc-500">{{ __('Ratio') }}</x-ise::text>
                        <x-ise::heading size="lg" class="mt-1">{{ $targetUser->getRatio() }}</x-ise::heading>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if (auth()->user()->isAdmin() && auth()->id() !== $targetUser->getAuthIdentifier())
        <div class="flex flex-wrap gap-2">
            @if (($targetUser->status ?? 'active') !== 'banned')
                <x-ise::button variant="danger" wire:click="ban" wire:confirm="{{ __('Ban this user?') }}">
                    {{ __('Ban User') }}
                </x-ise::button>
            @else
                <x-ise::button variant="primary" wire:click="unban">
                    {{ __('Unban User') }}
                </x-ise::button>
            @endif

            @foreach ($roles as $role)
                @if ($role->value !== ($targetUser->role->value ?? $targetUser->role))
                    <x-ise::button variant="ghost" wire:click="setRole('{{ $role->value }}')" wire:confirm="{{ __('Set role to :role?', ['role' => $role->value]) }}">
                        {{ __('Set :role', ['role' => ucfirst($role->value)]) }}
                    </x-ise::button>
                @endif
            @endforeach
        </div>
    @endif
</div>
