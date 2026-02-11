<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('My Invites') }}</flux:heading>
        @if ($canCreate)
            <flux:button variant="primary" :href="route('invites.create')" icon="plus" wire:navigate>
                {{ __('Create Invite') }}
            </flux:button>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <flux:text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</flux:text>
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Code') }}</flux:table.column>
                <flux:table.column>{{ __('Recipient') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Expires') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($invites as $invite)
                    <flux:table.row :key="$invite->id">
                        <flux:table.cell class="font-mono text-sm">{{ $invite->code }}</flux:table.cell>
                        <flux:table.cell>{{ $invite->recipient_email ?? '-' }}</flux:table.cell>
                        <flux:table.cell>{{ ucfirst($invite->status->value) }}</flux:table.cell>
                        <flux:table.cell>{{ $invite->expires_at?->diffForHumans() ?? '-' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($invite->status === \Marque\Usarrs\Enums\InviteStatus::Pending)
                                <flux:button variant="ghost" size="sm" wire:click="revoke({{ $invite->id }})" wire:confirm="{{ __('Revoke this invite?') }}">
                                    {{ __('Revoke') }}
                                </flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center py-8">
                            <flux:text class="text-zinc-500">{{ __('No invites yet.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($invites->hasPages())
        <div class="mt-4">
            {{ $invites->links() }}
        </div>
    @endif
</div>
