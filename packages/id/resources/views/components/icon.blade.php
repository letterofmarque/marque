@props([
    'name',
    'class' => 'size-4',
])

{{-- Inline Heroicons (outline, 24px) — only the set actually used by Marque views.
     Keeping these inline avoids a dependency on an icon package. --}}
@php
    $paths = [
        'arrow-left' => 'M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18',
        'arrow-down-tray' => 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3',
        'magnifying-glass' => 'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z',
        'pencil' => 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125',
        'plus' => 'M12 4.5v15m7.5-7.5h-15',
    ];
@endphp

@if (isset($paths[$name]))
    <svg
        {{ $attributes->class($class) }}
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
        stroke-width="1.5"
        stroke="currentColor"
        aria-hidden="true"
        data-icon="{{ $name }}"
    >
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $paths[$name] }}" />
    </svg>
@endif
