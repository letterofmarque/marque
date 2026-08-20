<nav class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
            {{-- Logo / App Name --}}
            <div class="flex items-center gap-6">
                <a href="/" class="text-lg font-bold text-zinc-900 dark:text-white">
                    {{ $appName }}
                </a>

                {{-- Nav Items --}}
                <div class="hidden items-center gap-1 sm:flex">
                    @foreach($items as $item)
                        <a href="{{ route($item['route']) }}"
                           @class([
                               'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition',
                               'text-zinc-900 bg-zinc-100 dark:text-white dark:bg-zinc-800' => request()->routeIs($item['route']),
                               'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:text-white dark:hover:bg-zinc-800' => !request()->routeIs($item['route']),
                           ])>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Right Side --}}
            <div class="flex items-center gap-4">
                @auth
                    <span class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ auth()->user()->name ?? auth()->user()->email }}
                    </span>
                @endauth

                @guest
                    @if(Route::has('login'))
                        <a href="{{ route('login') }}"
                           class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                            Log in
                        </a>
                    @endif
                @endguest
            </div>
        </div>
    </div>

    {{-- Mobile Nav --}}
    <div class="border-t border-zinc-200 dark:border-zinc-700 sm:hidden">
        <div class="space-y-1 px-4 py-2">
            @foreach($items as $item)
                <a href="{{ route($item['route']) }}"
                   @class([
                       'block rounded-lg px-3 py-2 text-sm font-medium',
                       'text-zinc-900 bg-zinc-100 dark:text-white dark:bg-zinc-800' => request()->routeIs($item['route']),
                       'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:bg-zinc-800' => !request()->routeIs($item['route']),
                   ])>
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    </div>
</nav>
