@props([
    'variant' => 'default',
    'size' => 'base',
    'icon' => null,
    'iconTrailing' => null,
    'href' => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg border font-medium transition
             focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-blue-500
             dark:focus-visible:ring-offset-zinc-900
             disabled:pointer-events-none disabled:opacity-50';

    $variants = [
        'primary' => 'border-transparent bg-zinc-900 text-white hover:bg-zinc-700
                      dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200',
        'danger' => 'border-transparent bg-red-600 text-white hover:bg-red-500
                     dark:bg-red-600 dark:hover:bg-red-500',
        'outline' => 'border-zinc-300 bg-white text-zinc-800 hover:bg-zinc-50
                      dark:border-zinc-600 dark:bg-transparent dark:text-zinc-200 dark:hover:bg-zinc-800',
        'ghost' => 'border-transparent bg-transparent text-zinc-700 hover:bg-zinc-100
                    dark:text-zinc-300 dark:hover:bg-zinc-800',
        'default' => 'border-zinc-200 bg-white text-zinc-800 shadow-xs hover:bg-zinc-50
                      dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-600',
    ];

    $sizes = [
        'sm' => 'h-8 px-3 text-xs',
        'base' => 'h-10 px-4 text-sm',
        'lg' => 'h-12 px-5 text-base',
    ];

    $classes = trim(preg_replace('/\s+/', ' ', implode(' ', [
        $base,
        $variants[$variant] ?? $variants['default'],
        $sizes[$size] ?? $sizes['base'],
    ])));

    $iconSize = $size === 'sm' ? 'size-3.5' : 'size-4';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)
            <x-id::icon :name="$icon" :class="$iconSize" />
        @endif

        {{ $slot }}

        @if ($iconTrailing)
            <x-id::icon :name="$iconTrailing" :class="$iconSize" />
        @endif
    </a>
@else
    <button {{ $attributes->class($classes)->merge(['type' => 'button']) }}>
        @if ($icon)
            <x-id::icon :name="$icon" :class="$iconSize" />
        @endif

        {{ $slot }}

        @if ($iconTrailing)
            <x-id::icon :name="$iconTrailing" :class="$iconSize" />
        @endif
    </button>
@endif
