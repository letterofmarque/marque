<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <x-id::heading size="xl">{{ __('My Invites') }}</x-id::heading>
        @if ($canCreate)
            <x-id::button variant="primary" :href="route('invites.create')" icon="plus" wire:navigate>
                {{ __('Create Invite') }}
            </x-id::button>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-id::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-id::text>
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <x-id::table>
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Code') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Recipient') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Expires') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($invites as $invite)
                    <tr wire:key="{{ $invite->id }}">
                        <td class="px-3 py-2 font-mono text-sm">{{ $invite->code }}</td>
                        <td class="px-3 py-2">{{ $invite->recipient_email ?? '-' }}</td>
                        <td class="px-3 py-2">{{ ucfirst($invite->status->value) }}</td>
                        <td class="px-3 py-2">{{ $invite->expires_at?->diffForHumans() ?? '-' }}</td>
                        <td class="px-3 py-2">
                            @if ($invite->status === \Marque\Usarrs\Enums\InviteStatus::Pending)
                                <x-id::button variant="ghost" size="sm" wire:click="revoke({{ $invite->id }})" wire:confirm="{{ __('Revoke this invite?') }}">
                                    {{ __('Revoke') }}
                                </x-id::button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-8 text-center">
                            <x-id::text class="text-zinc-500">{{ __('No invites yet.') }}</x-id::text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-id::table>
    </div>

    @if ($invites->hasPages())
        <div class="mt-4">
            {{ $invites->links() }}
        </div>
    @endif
</div>
