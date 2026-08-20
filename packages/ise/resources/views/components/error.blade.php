@props([
    'name' => null,
])

@php
    $classes = 'text-sm text-red-600 dark:text-red-400';
@endphp

@if ($name)
    @error($name)
        <p {{ $attributes->class($classes) }}>{{ $message }}</p>
    @enderror
@elseif (trim($slot) !== '')
    <p {{ $attributes->class($classes) }}>{{ $slot }}</p>
@endif
