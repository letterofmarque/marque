<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center gap-4">
        <x-id::button variant="ghost" :href="route('parley.forum.categories.index')" icon="arrow-left" wire:navigate>
            {{ __('Back') }}
        </x-id::button>
    </div>

    <div class="flex items-center justify-between">
        <x-id::heading size="xl">{{ $category->name }}</x-id::heading>

        @can('create', \Marque\Parley\Models\Thread::class)
            <x-id::button variant="primary" :href="route('parley.forum.threads.create', $category)" icon="plus" wire:navigate>
                {{ __('New Thread') }}
            </x-id::button>
        @endcan
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <x-id::table>
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Thread') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Started by') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Last activity') }}</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($threads as $thread)
                    <tr wire:key="{{ $thread->id }}">
                        <td class="px-3 py-2 font-medium">
                            @if ($thread->pinned)
                                <span class="mr-1 text-xs font-semibold text-zinc-500" title="{{ __('Pinned') }}">📌</span>
                            @endif
                            @if ($thread->locked)
                                <span class="mr-1 text-xs font-semibold text-zinc-500" title="{{ __('Locked') }}">🔒</span>
                            @endif
                            <a href="{{ route('parley.forum.threads.show', $thread) }}" class="hover:underline" wire:navigate>
                                {{ $thread->title }}
                            </a>
                        </td>
                        <td class="px-3 py-2">{{ $thread->user->name }}</td>
                        <td class="px-3 py-2">{{ $thread->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-3 py-8 text-center">
                            <x-id::text class="text-zinc-500">{{ __('No threads yet.') }}</x-id::text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-id::table>
    </div>

    @if ($threads->hasPages())
        <div class="mt-4">
            {{ $threads->links() }}
        </div>
    @endif
</div>
