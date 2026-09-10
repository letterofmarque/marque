<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-deck::heading size="xl" class="mb-6">{{ $title }}</x-deck::heading>

    @forelse ($groups as $group => $screens)
        <section class="mb-8">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {{ $group }}
            </h2>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($screens as $screen)
                    <a href="{{ route($screen['route']) }}"
                       wire:navigate
                       class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-white px-4 py-3 text-sm font-medium text-zinc-900 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:hover:border-zinc-600 dark:hover:bg-zinc-800">
                        @if ($screen['icon'])
                            <x-deck::icon :name="$screen['icon']" class="size-5 text-zinc-400" />
                        @endif
                        {{ $screen['label'] }}
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        {{-- A fresh install has skipper and nothing else. That is a normal
             state, not a broken one, so say so plainly rather than showing an
             empty page. --}}
        <div class="rounded-lg border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-700">
            <x-deck::text class="text-zinc-500 dark:text-zinc-400">
                {{ __('No admin screens are registered yet.') }}
            </x-deck::text>
            <x-deck::text class="mt-1 text-sm text-zinc-400 dark:text-zinc-500">
                {{ __('Packages that provide admin screens will appear here once installed.') }}
            </x-deck::text>
        </div>
    @endforelse
</div>
