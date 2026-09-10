@props([
    'size' => 'base',
    'level' => null,
])

@php
    $sizes = [
        'sm' => 'text-sm font-medium',
        'base' => 'text-base font-semibold',
        'lg' => 'text-lg font-semibold',
        'xl' => 'text-xl font-semibold',
        '2xl' => 'text-2xl font-bold',
    ];

    $tags = ['sm' => 'h3', 'base' => 'h3', 'lg' => 'h2', 'xl' => 'h1', '2xl' => 'h1'];

    $tag = $level ? 'h'.$level : ($tags[$size] ?? 'h2');
    $classes = ($sizes[$size] ?? $sizes['base']).' text-zinc-900 dark:text-white';
@endphp

<{{ $tag }} {{ $attributes->class($classes) }}>{{ $slot }}</{{ $tag }}>
