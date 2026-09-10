@props([
    'as' => 'p',
])

<{{ $as }} {{ $attributes->class('text-base text-zinc-700 dark:text-zinc-300') }}>{{ $slot }}</{{ $as }}>
