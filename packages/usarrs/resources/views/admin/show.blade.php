<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <flux:button variant="ghost" :href="route('admin.users.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </flux:button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4">
            <flux:heading size="xl">{{ $targetUser->name }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <flux:text class="text-sm text-zinc-500">{{ __('Email') }}</flux:text>
                    <flux:text>{{ $targetUser->email }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500">{{ __('Role') }}</flux:text>
                    <flux:text>{{ ucfirst($targetUser->role->value ?? $targetUser->role) }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500">{{ __('Status') }}</flux:text>
                    <flux:text>{{ ucfirst($targetUser->status ?? 'active') }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500">{{ __('Joined') }}</flux:text>
                    <flux:text>{{ $targetUser->created_at?->diffForHumans() ?? 'N/A' }}</flux:text>
                </div>
            </div>

            @if ($hasTrackerStats)
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text class="text-sm text-zinc-500">{{ __('Uploaded') }}</flux:text>
                        <flux:heading size="lg" class="mt-1">{{ Number::fileSize($targetUser->uploaded ?? 0) }}</flux:heading>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text class="text-sm text-zinc-500">{{ __('Downloaded') }}</flux:text>
                        <flux:heading size="lg" class="mt-1">{{ Number::fileSize($targetUser->downloaded ?? 0) }}</flux:heading>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text class="text-sm text-zinc-500">{{ __('Ratio') }}</flux:text>
                        <flux:heading size="lg" class="mt-1">{{ $targetUser->getRatio() }}</flux:heading>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if (auth()->user()->isAdmin() && auth()->id() !== $targetUser->getAuthIdentifier())
        <div class="flex flex-wrap gap-2">
            @if (($targetUser->status ?? 'active') !== 'banned')
                <flux:button variant="danger" wire:click="ban" wire:confirm="{{ __('Ban this user?') }}">
                    {{ __('Ban User') }}
                </flux:button>
            @else
                <flux:button variant="primary" wire:click="unban">
                    {{ __('Unban User') }}
                </flux:button>
            @endif

            @foreach ($roles as $role)
                @if ($role->value !== ($targetUser->role->value ?? $targetUser->role))
                    <flux:button variant="ghost" wire:click="setRole('{{ $role->value }}')" wire:confirm="{{ __('Set role to :role?', ['role' => $role->value]) }}">
                        {{ __('Set :role', ['role' => ucfirst($role->value)]) }}
                    </flux:button>
                @endif
            @endforeach
        </div>
    @endif
</div>
