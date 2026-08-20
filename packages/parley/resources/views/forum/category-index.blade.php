<div class="flex h-full w-full flex-1 flex-col gap-4">
    <x-ise::heading size="xl">{{ __('Forum') }}</x-ise::heading>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <x-ise::table>
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Category') }}</th>
                    <th class="px-3 py-2 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Threads') }}</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($categories as $category)
                    <tr wire:key="{{ $category->id }}">
                        <td class="px-3 py-2">
                            <a href="{{ route('parley.forum.categories.show', $category) }}" class="font-medium hover:underline" wire:navigate>
                                {{ $category->name }}
                            </a>
                            @if ($category->description)
                                <x-ise::text class="mt-0.5 text-sm text-zinc-500">{{ $category->description }}</x-ise::text>
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $category->threads_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-3 py-8 text-center">
                            <x-ise::text class="text-zinc-500">{{ __('No categories yet.') }}</x-ise::text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ise::table>
    </div>
</div>
