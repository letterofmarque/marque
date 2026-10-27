<div class="flex h-full w-full flex-1 flex-col gap-4">
    <x-deck::heading size="xl">{{ __('Audit log') }}</x-deck::heading>

    <div class="flex flex-wrap items-end gap-3">
        <label class="flex flex-col gap-1 text-sm">
            <span class="text-zinc-500">{{ __('Staff member') }}</span>
            <select wire:model.live="actor" class="rounded-lg border border-zinc-200 px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-900">
                <option value="">{{ __('Anyone') }}</option>
                @foreach ($staff as $member)
                    <option value="{{ $member->getKey() }}">{{ $member->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1 text-sm">
            <span class="text-zinc-500">{{ __('Action') }}</span>
            <select wire:model.live="action" class="rounded-lg border border-zinc-200 px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-900">
                <option value="">{{ __('Any') }}</option>
                @foreach ($actions as $name)
                    <option value="{{ $name }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1 text-sm">
            <span class="text-zinc-500">{{ __('Subject') }}</span>
            <div class="flex gap-1">
                <x-deck::input wire:model.live.debounce.300ms="subjectType" placeholder="{{ __('type') }}" class="w-32" />
                <x-deck::input wire:model.live.debounce.300ms="subjectId" placeholder="{{ __('id') }}" class="w-20" />
            </div>
        </label>

        <label class="flex flex-col gap-1 text-sm">
            <span class="text-zinc-500">{{ __('From') }}</span>
            <x-deck::input type="date" wire:model.live="from" />
        </label>

        <label class="flex flex-col gap-1 text-sm">
            <span class="text-zinc-500">{{ __('To') }}</span>
            <x-deck::input type="date" wire:model.live="to" />
        </label>
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <x-deck::table>
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('When') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Who') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Action') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Subject') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Why') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Changed') }}</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($entries as $entry)
                    <tr wire:key="entry-{{ $entry->id }}">
                        <td class="px-3 py-2 whitespace-nowrap" title="{{ $entry->created_at->toDateTimeString() }}">{{ $entry->created_at->diffForHumans() }}</td>
                        <td class="px-3 py-2">{{ $entry->actor?->name ?? __('User #:id', ['id' => $entry->actor_id]) }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $entry->action }}</td>
                        <td class="px-3 py-2">{{ class_basename($entry->subject_type) }} #{{ $entry->subject_id }}</td>
                        <td class="px-3 py-2">{{ $entry->reason }}</td>
                        <td class="px-3 py-2 text-xs">
                            {{-- Read by name: MySQL's JSON type doesn't keep key order. --}}
                            @foreach ($entry->changes ?? [] as $field => $change)
                                <div>
                                    <span class="text-zinc-500">{{ $field }}:</span>
                                    {{ \Illuminate\Support\Str::limit(var_export($change['from'] ?? null, true), 80) }}
                                    &rarr;
                                    {{ \Illuminate\Support\Str::limit(var_export($change['to'] ?? null, true), 80) }}
                                </div>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-3 py-8 text-center">
                            <x-deck::text class="text-zinc-500">{{ __('No staff actions match.') }}</x-deck::text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-deck::table>
    </div>

    @if ($entries->hasPages())
        <div class="mt-4">
            {{ $entries->links() }}
        </div>
    @endif
</div>
