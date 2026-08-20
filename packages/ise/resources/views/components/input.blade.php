@props([
    'icon' => null,
    'type' => 'text',
])

@php
    $control = 'w-full rounded-lg border border-zinc-300 bg-white text-sm text-zinc-900 shadow-xs
                placeholder:text-zinc-400
                focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-900/10
                disabled:cursor-not-allowed disabled:opacity-50
                dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100
                dark:placeholder:text-zinc-500 dark:focus:border-zinc-500 dark:focus:ring-white/10';

    $control = trim(preg_replace('/\s+/', ' ', $control));

    $padding = $icon ? 'h-10 py-2 pl-10 pr-3' : 'h-10 px-3 py-2';

    if ($type === 'file') {
        $padding = 'py-2 px-3 h-auto file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100
                    file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700
                    dark:file:bg-zinc-700 dark:file:text-zinc-200';
        $padding = trim(preg_replace('/\s+/', ' ', $padding));
    }
@endphp

@if ($icon)
    <div class="relative {{ $attributes->get('class') ? '' : 'w-full' }}">
        <x-ise::icon
            :name="$icon"
            class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400 dark:text-zinc-500"
        />
        <input type="{{ $type }}" {{ $attributes->class([$control, $padding]) }} />
    </div>
@else
    <input type="{{ $type }}" {{ $attributes->class([$control, $padding]) }} />
@endif
