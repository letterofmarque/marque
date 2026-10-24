{{--
    Items an app or package registered with trove's FooterItemRegistry render
    between the copyright and "Powered by", ordered by position (#11100), so
    adding a line never means publishing and copying this view. Filtered here,
    on the server: an item whose closure declines is absent from the page, not
    hidden in it.
--}}
@php($footerItems = app(\Marque\Trove\Registry\FooterItemRegistry::class)->visibleTo(auth()->user()))
<footer class="border-t border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                &copy; {{ date('Y') }} {{ config('deck.app_name', 'Marque') }}
            </p>
            @if ($footerItems !== [])
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-zinc-500 dark:text-zinc-400">
                    @foreach ($footerItems as $footerItem)
                        <div data-footer-item="{{ $footerItem->identifier }}">@include($footerItem->view)</div>
                    @endforeach
                </div>
            @endif
            <p class="text-sm text-zinc-400 dark:text-zinc-500">
                Powered by <a href="https://github.com/letterofmarque/marque" class="hover:text-zinc-600 dark:hover:text-zinc-300" target="_blank" rel="noopener">Marque</a>
            </p>
        </div>
    </div>
</footer>
