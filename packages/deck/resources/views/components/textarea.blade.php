@props([
    'rows' => 4,
])

@php
    $control = 'w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-xs
                placeholder:text-zinc-400
                focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-900/10
                disabled:cursor-not-allowed disabled:opacity-50
                dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100
                dark:placeholder:text-zinc-500 dark:focus:border-zinc-500 dark:focus:ring-white/10';

    $control = trim(preg_replace('/\s+/', ' ', $control));
@endphp

<textarea rows="{{ $rows }}" {{ $attributes->class($control) }}>{{ $slot }}</textarea>
