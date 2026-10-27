<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-deck::heading size="xl">{{ __('Staff') }}</x-deck::heading>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <x-deck::table>
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Name') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Role') }}</th>
                    <th class="px-3 py-2 text-right font-medium text-zinc-500 dark:text-zinc-400">{{ __('7 days') }}</th>
                    <th class="px-3 py-2 text-right font-medium text-zinc-500 dark:text-zinc-400">{{ __('30 days') }}</th>
                    <th class="px-3 py-2 text-right font-medium text-zinc-500 dark:text-zinc-400">{{ __('90 days') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Last seen') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($members as $member)
                    @php($id = (int) $member->getKey())
                    <tr wire:key="staff-{{ $id }}">
                        <td class="px-3 py-2 font-medium">{{ $member->name }}</td>
                        <td class="px-3 py-2">{{ ucfirst($roster->roleOf($member)->value) }}</td>
                        <td class="px-3 py-2 text-right">{{ $counts[$id][7] ?? 0 }}</td>
                        <td class="px-3 py-2 text-right">{{ $counts[$id][30] ?? 0 }}</td>
                        <td class="px-3 py-2 text-right">{{ $counts[$id][90] ?? 0 }}</td>
                        <td class="px-3 py-2">
                            @if (isset($lastSeen[$id]))
                                {{ \Illuminate\Support\Carbon::parse($lastSeen[$id])->diffForHumans() }}
                            @else
                                <span class="text-zinc-500">{{ __('Never') }}</span>
                            @endif
                        </td>
                        <td class="flex justify-end gap-2 px-3 py-2">
                            @if ($auditRoute)
                                <x-deck::button variant="ghost" size="sm" :href="route($auditRoute, ['actor' => $id])">
                                    {{ __('Actions') }}
                                </x-deck::button>
                            @endif
                            @if ($changeable[$id] ?? false)
                                <x-deck::button variant="ghost" size="sm" wire:click="startChange({{ $id }})">
                                    {{ __('Change role') }}
                                </x-deck::button>
                            @endif
                        </td>
                    </tr>

                    @if ($changingMember && (int) $changingMember->getKey() === $id)
                        <tr wire:key="change-{{ $id }}">
                            <td colspan="7" class="bg-zinc-50 px-3 py-3 dark:bg-zinc-900">
                                <form wire:submit="applyChange" class="flex flex-wrap items-end gap-3">
                                    <label class="flex flex-col gap-1 text-sm">
                                        <span class="text-zinc-500">{{ __('New role') }}</span>
                                        <select wire:model="role" class="rounded-lg border border-zinc-200 px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-900">
                                            <option value="">{{ __('Choose…') }}</option>
                                            @foreach ($offered as $option)
                                                <option value="{{ $option->value }}">{{ ucfirst($option->value) }}</option>
                                            @endforeach
                                        </select>
                                        <x-deck::error name="role" />
                                    </label>
                                    <label class="flex flex-1 flex-col gap-1 text-sm">
                                        <span class="text-zinc-500">{{ __('Why') }}</span>
                                        <x-deck::input wire:model="reason" />
                                        <x-deck::error name="reason" />
                                    </label>
                                    <x-deck::button type="submit" variant="primary" size="sm">{{ __('Change role') }}</x-deck::button>
                                    <x-deck::button variant="ghost" size="sm" wire:click="cancel">{{ __('Cancel') }}</x-deck::button>
                                </form>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-8 text-center">
                            <x-deck::text class="text-zinc-500">{{ __('No staff yet.') }}</x-deck::text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-deck::table>
    </div>

    @if ($addable !== [])
        <div class="flex flex-col gap-2">
            <x-deck::heading size="lg">{{ __('Add to the staff') }}</x-deck::heading>
            <form wire:submit="addToStaff" class="flex flex-wrap items-end gap-3">
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-zinc-500">{{ __('Their address') }}</span>
                    <x-deck::input type="email" wire:model="addEmail" />
                    <x-deck::error name="addEmail" />
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-zinc-500">{{ __('Role') }}</span>
                    <select wire:model="role" class="rounded-lg border border-zinc-200 px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-900">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($addable as $option)
                            <option value="{{ $option->value }}">{{ ucfirst($option->value) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-1 flex-col gap-1 text-sm">
                    <span class="text-zinc-500">{{ __('Why') }}</span>
                    <x-deck::input wire:model="reason" />
                </label>
                <x-deck::button type="submit" variant="primary" size="sm">{{ __('Add') }}</x-deck::button>
            </form>
        </div>
    @endif
</div>
