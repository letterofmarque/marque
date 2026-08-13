<div class="flex h-full w-full flex-1 flex-col gap-4">
    <x-id::heading size="xl">{{ __('Users') }}</x-id::heading>

    <div class="flex items-center gap-4">
        <x-id::input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search users...') }}"
            icon="magnifying-glass"
            class="max-w-sm"
        />
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <x-id::table>
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Name') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Email') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Role') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($users as $user)
                    <tr wire:key="{{ $user->id }}">
                        <td class="px-3 py-2 font-medium">{{ $user->name }}</td>
                        <td class="px-3 py-2">{{ $user->email }}</td>
                        <td class="px-3 py-2">{{ ucfirst($user->role->value ?? $user->role) }}</td>
                        <td class="px-3 py-2">{{ ucfirst($user->status ?? 'active') }}</td>
                        <td class="px-3 py-2">
                            <x-id::button variant="ghost" size="sm" :href="route('admin.users.show', $user)" wire:navigate>
                                {{ __('View') }}
                            </x-id::button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-8 text-center">
                            <x-id::text class="text-zinc-500">{{ __('No users found.') }}</x-id::text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-id::table>
    </div>

    @if ($users->hasPages())
        <div class="mt-4">
            {{ $users->links() }}
        </div>
    @endif
</div>
