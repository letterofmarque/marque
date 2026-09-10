@props([
    'for' => null,
])

<label
    @if ($for) for="{{ $for }}" @endif
    {{ $attributes->class('text-sm font-medium text-zinc-800 dark:text-zinc-200') }}
>
    {{ $slot }}
</label>
