<div class="flex h-full w-full flex-1 flex-col gap-4">
    <x-deck::heading size="xl">{{ __('Seedboxes') }}</x-deck::heading>

    <div class="flex items-center gap-2">
        @foreach ($statuses as $option)
            <x-deck::button :variant="$status === $option ? 'primary' : 'ghost'" size="sm" wire:click="$set('status', '{{ $option }}')">
                {{ __(ucfirst($option)) }}
            </x-deck::button>
        @endforeach
    </div>

    @if (session('status'))
        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
            <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <x-deck::table>
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Member') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Network') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Label') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Registered') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($seedboxes as $seedbox)
                    <tr wire:key="seedbox-{{ $seedbox->id }}">
                        <td class="px-3 py-2 font-medium">{{ $members->get($seedbox->userId)?->name ?? '#'.$seedbox->userId }}</td>
                        <td class="px-3 py-2">{{ $seedbox->network }}</td>
                        <td class="px-3 py-2">{{ $seedbox->label }}</td>
                        <td class="px-3 py-2">{{ $seedbox->registeredAt->format('Y-m-d') }}</td>
                        <td class="px-3 py-2 text-right">
                            @if ($seedbox->status !== 'approved')
                                <x-deck::button variant="primary" size="sm" wire:click="approve({{ $seedbox->id }})">{{ __('Approve') }}</x-deck::button>
                            @endif
                            @if ($seedbox->status !== 'revoked')
                                <x-deck::button variant="ghost" size="sm" wire:click="revoke({{ $seedbox->id }})" wire:confirm="{{ __('Revoke this seedbox? Its announces go back to the ordinary speed limit.') }}">{{ __('Revoke') }}</x-deck::button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-8 text-center">
                            <x-deck::text>{{ __('No seedboxes here.') }}</x-deck::text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-deck::table>
    </div>
</div>
