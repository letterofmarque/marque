<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-4">
        <x-deck::button variant="ghost" :href="route('profile.stats')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-deck::button>
    </div>

    <div class="max-w-2xl">
        <x-deck::heading size="xl" class="mb-2">{{ __('Seedboxes') }}</x-deck::heading>
        <x-deck::text class="mb-6">
            {{ __('A seedbox is held to a higher speed limit than a home connection. Register the address, or the range your provider gave you.') }}
        </x-deck::text>

        @if (session('status'))
            <div class="mb-6 rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <x-deck::text class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</x-deck::text>
            </div>
        @endif

        <form wire:submit="register" class="mb-8 flex flex-col gap-4">
            <x-deck::field :label="__('Address or range')" name="network">
                <x-deck::input wire:model="network" placeholder="203.0.113.7 or 2001:db8::/64" required />
            </x-deck::field>

            <x-deck::field :label="__('Label')" name="label">
                <x-deck::input wire:model="label" placeholder="{{ __('Optional, e.g. the provider') }}" />
            </x-deck::field>

            <div>
                <x-deck::button type="submit" variant="primary">{{ __('Add seedbox') }}</x-deck::button>
            </div>
        </form>

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
            <x-deck::table>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($seedboxes as $seedbox)
                        <tr wire:key="seedbox-{{ $seedbox->id }}">
                            <td class="px-3 py-2 font-medium">{{ $seedbox->network }}</td>
                            <td class="px-3 py-2">{{ $seedbox->label }}</td>
                            <td class="px-3 py-2">
                                @switch($seedbox->status)
                                    @case('approved') {{ __('Approved') }} @break
                                    @case('revoked') {{ __('Revoked') }} @break
                                    @default {{ __('Waiting for approval') }}
                                @endswitch
                            </td>
                            <td class="px-3 py-2 text-right">
                                <x-deck::button variant="ghost" size="sm" wire:click="remove({{ $seedbox->id }})" wire:confirm="{{ __('Remove this seedbox?') }}">
                                    {{ __('Remove') }}
                                </x-deck::button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-8 text-center">
                                <x-deck::text>{{ __('No seedboxes registered.') }}</x-deck::text>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-deck::table>
        </div>
    </div>
</div>
