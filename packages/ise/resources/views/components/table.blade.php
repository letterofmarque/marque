@props([])

<div class="w-full overflow-x-auto">
    <table {{ $attributes->class('w-full text-left text-sm') }}>
        {{ $slot }}
    </table>
</div>
